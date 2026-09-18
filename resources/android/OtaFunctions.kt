package com.nativephp.plugins.mobile_ota

import android.app.AlertDialog
import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.content.SharedPreferences
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeError
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import org.json.JSONObject
import java.io.File
import java.net.HttpURLConnection
import java.net.URL

object OtaFunctions {
    private const val PREFS = "nativephp_ota"
    private const val KEY_VERSION = "version"

    private fun prefs(context: Context): SharedPreferences =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    // Plugin-owned backup of the last pending zip. Must NOT live under
    // app_storage/updates — core applies any *.zip there on the next boot.
    private fun otaDir(context: Context): File =
        File(context.filesDir, "ota").apply { mkdirs() }

    private fun previousZip(context: Context) = File(otaDir(context), "previous.zip")

    // Core pending location: {appStorageDir}/updates/pending.zip
    // Matches LaravelEnvironment.appStorageDir = context.getDir("storage", MODE_PRIVATE)
    private fun appStorageDir(context: Context): File =
        context.getDir("storage", Context.MODE_PRIVATE)

    private fun updatesDir(context: Context): File =
        File(appStorageDir(context), "updates").apply { mkdirs() }

    private fun pendingZip(context: Context) = File(updatesDir(context), "pending.zip")

    // Written after the zip, so its absence marks an interrupted download.
    private fun pendingManifest(context: Context) = File(updatesDir(context), "pending.json")

    private fun installedVersion(context: Context): String {
        val p = prefs(context)
        return try {
            p.getString(KEY_VERSION, null)?.takeIf { it.isNotEmpty() }
                ?: p.getInt(KEY_VERSION, 0).toString()
        } catch (_: ClassCastException) {
            try {
                p.getInt(KEY_VERSION, 0).toString()
            } catch (_: ClassCastException) {
                "0"
            }
        }
    }

    private fun storeVersion(context: Context, version: String) {
        prefs(context).edit().putString(KEY_VERSION, version).apply()
    }

    private fun versionParam(parameters: Map<String, Any>, context: Context): String {
        return when (val v = parameters["version"]) {
            is String -> v.ifEmpty { installedVersion(context) }
            is Number -> v.toString()
            else -> installedVersion(context)
        }
    }

    // Copy the current pending zip aside before it is overwritten so rollback
    // can re-drop it as pending for the next core boot.
    private fun backupPendingIfPresent(context: Context) {
        val pending = pendingZip(context)
        if (!pending.exists()) return
        val previous = previousZip(context)
        if (previous.exists()) previous.delete()
        pending.copyTo(previous, overwrite = true)
    }

    class Check(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            return runCatching { checkForUpdate(context, parameters) }
                .getOrElse { unavailable(versionParam(parameters, context), it.message ?: "check failed") }
        }
    }

    class Download(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val url = parameters["url"] as? String
                ?: return BridgeResponse.error(BridgeError.InvalidParameters("url is required"))
            return runCatching {
                backupPendingIfPresent(context)
                val pending = pendingZip(context)
                val bytes = URL(url).openStream().use { it.readBytes() }

                // A payload that does not match what the server described is not
                // the release we were offered, so it never reaches the location
                // core extracts from.
                val expectedSha = parameters["sha256"] as? String
                if (!expectedSha.isNullOrBlank()) {
                    val actual = java.security.MessageDigest.getInstance("SHA-256")
                        .digest(bytes)
                        .joinToString("") { "%02x".format(it) }
                    if (!actual.equals(expectedSha, ignoreCase = true)) {
                        return BridgeResponse.error(BridgeError.ExecutionFailed("checksum mismatch"))
                    }
                }
                val expectedSize = (parameters["size"] as? Number)?.toInt() ?: 0
                if (expectedSize > 0 && bytes.size != expectedSize) {
                    return BridgeResponse.error(BridgeError.ExecutionFailed("size mismatch: expected $expectedSize, got ${bytes.size}"))
                }

                pending.writeBytes(bytes)

                // What the server said about these bytes, written after them:
                // core treats its absence as an interrupted download, and moves
                // it into the app as ota.json once the payload is applied. The
                // signed download URL is deliberately not persisted.
                (parameters["release"] as? String)?.takeIf { it.isNotBlank() }?.let { release ->
                    val manifest = JSONObject().put("release_uuid", release)
                    for (key in listOf("sha256", "size", "commit", "published_at", "arc", "shell_fingerprint")) {
                        parameters[key]?.let { manifest.put(key, it) }
                    }
                    pendingManifest(context).writeText(manifest.toString(2))
                }

                when (val v = parameters["version"]) {
                    is String -> if (v.isNotEmpty()) storeVersion(context, v)
                    is Number -> storeVersion(context, v.toString())
                }
                BridgeResponse.success(mapOf(
                    "success" to true,
                    "path" to pending.absolutePath,
                    "queued" to true,
                    "applyOnNextBoot" to true
                ))
            }.getOrElse { BridgeResponse.error(BridgeError.ExecutionFailed(it.message ?: "download failed")) }
        }
    }

    class Apply(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val pending = pendingZip(context)
            if (!pending.exists()) {
                return BridgeResponse.error(BridgeError.ExecutionFailed("no pending payload"))
            }
            val version = versionParam(parameters, context)
            storeVersion(context, version)
            // Core extracts {appStorageDir}/updates/*.zip on the next boot.
            // Do not unzip into the running Laravel tree from the plugin.
            return BridgeResponse.success(mapOf(
                "success" to true,
                "version" to version,
                "current_version" to version,
                "queued" to true,
                "applyOnNextBoot" to true,
                "restartRequired" to true
            ))
        }
    }

    class Rollback(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val pending = pendingZip(context)
            val previous = previousZip(context)
            if (!previous.exists()) {
                return BridgeResponse.error(BridgeError.ExecutionFailed("no previous payload"))
            }
            val tmp = File(otaDir(context), "swap.zip")
            if (pending.exists()) pending.copyTo(tmp, overwrite = true)
            previous.copyTo(pending, overwrite = true)
            if (tmp.exists()) {
                tmp.copyTo(previous, overwrite = true)
                tmp.delete()
            }
            val version = installedVersion(context)
            return BridgeResponse.success(mapOf(
                "success" to true,
                "version" to version,
                "current_version" to version,
                "queued" to true,
                "applyOnNextBoot" to true,
                "restartRequired" to true
            ))
        }
    }

    class GetStatus(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val version = installedVersion(context)
            val pending = pendingZip(context).exists()
            return BridgeResponse.success(mapOf(
                "version" to version,
                "current_version" to version,
                "hasPrevious" to previousZip(context).exists(),
                "pending" to pending,
                "queued" to pending,
                "applyOnNextBoot" to pending
            ))
        }
    }

    class Prompt(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val check = checkForUpdate(activity, parameters)
            val data = check["data"] as? Map<*, *>
            val available = data?.get("available") == true
            if (!available) {
                return BridgeResponse.success(mapOf("available" to false, "accepted" to false))
            }
            var accepted = false
            activity.runOnUiThread {
                AlertDialog.Builder(activity)
                    .setTitle("Update available")
                    .setMessage("Download and apply this OTA update?")
                    .setPositiveButton("Update") { _, _ -> accepted = true }
                    .setNegativeButton("Later", null)
                    .show()
            }
            val currentVersion = (data?.get("current_version") as? String)
                ?: (data?.get("version") as? String)
                ?: ""
            return BridgeResponse.success(mapOf(
                "available" to true,
                "accepted" to accepted,
                "version" to currentVersion,
                "current_version" to currentVersion,
                "download_url" to (data?.get("download_url") ?: ""),
                "url" to (data?.get("url") ?: data?.get("download_url") ?: "")
            ))
        }
    }

    private fun parseUpToDate(value: Any?): Boolean? {
        return when (value) {
            is Boolean -> value
            is Number -> value.toInt() != 0
            is String -> when (value.lowercase()) {
                "1", "true", "yes" -> true
                "0", "false", "no" -> false
                else -> null
            }
            else -> null
        }
    }

    private fun unavailable(version: String, reason: String): Map<String, Any> {
        return BridgeResponse.success(mapOf(
            "available" to false,
            "upToDate" to true,
            "current_version" to version,
            "download_url" to "",
            "version" to version,
            "url" to "",
            "reason" to reason
        ))
    }


    private fun isOnline(context: Context): Boolean {
        val cm = context.getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager
            ?: return false
        val network = cm.activeNetwork ?: return false
        val caps = cm.getNetworkCapabilities(network) ?: return false
        return caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
    }

    private fun checkForUpdate(context: Context, parameters: Map<String, Any>): Map<String, Any> {
        val endpoint = parameters["endpoint"] as? String
        val project = parameters["project"] as? String
        val arc = parameters["arc"] as? String
        val fingerprint = parameters["fingerprint"] as? String
        val algorithm = parameters["algorithm"] as? String ?: "1"
        val release = parameters["release"] as? String
        val version = versionParam(parameters, context)

        if (endpoint.isNullOrBlank() || project.isNullOrBlank() || arc.isNullOrBlank() || fingerprint.isNullOrBlank()) {
            return unavailable(version, "missing endpoint, project, arc or fingerprint")
        }
        if (!isOnline(context)) {
            return unavailable(version, "offline")
        }

        // The shell says who it is — app, arc, the fingerprint it was built
        // against — and which release it already holds. The answer is whatever
        // that lane points at.
        val trimmed = endpoint.trimEnd('/')
        val held = if (release.isNullOrBlank()) "" else "/$release"
        val url = "$trimmed/api/v1/apps/$project/$arc/$fingerprint$held?algorithm=$algorithm"
        val connection = URL(url).openConnection() as HttpURLConnection
        connection.requestMethod = "GET"
        connection.setRequestProperty("Accept", "application/json")
        (parameters["token"] as? String)?.takeIf { it.isNotBlank() }?.let {
            connection.setRequestProperty("Authorization", "Bearer $it")
        }
        connection.connectTimeout = 15000
        connection.readTimeout = 15000
        try {
            val code = connection.responseCode
            val stream = if (code in 200..299) connection.inputStream else connection.errorStream
            val text = stream?.bufferedReader()?.use { it.readText() } ?: ""
            if (code !in 200..299) {
                return unavailable(version, "http $code")
            }
            if (text.isBlank()) {
                return unavailable(version, "json missing")
            }
            val body = try {
                JSONObject(text)
            } catch (_: Exception) {
                return unavailable(version, "json missing")
            }
            val upToDate = parseUpToDate(body.opt("up_to_date"))
                ?: return unavailable(version, "unexpected response shape")
            val offered = body.optJSONObject("release") ?: JSONObject()
            val releaseUuid = offered.optString("uuid", "")
            val downloadUrl = if (body.has("download_url") && !body.isNull("download_url")) {
                body.optString("download_url", "")
            } else {
                ""
            }
            val available = !upToDate && downloadUrl.isNotBlank() && releaseUuid.isNotBlank()

            return BridgeResponse.success(mapOf(
                "available" to available,
                "upToDate" to upToDate,
                "requested" to url,
                "status" to code,
                "release" to releaseUuid,
                "sha256" to offered.optString("sha256", ""),
                "size" to offered.optInt("size", 0),
                "commit" to offered.optString("commit", ""),
                "published_at" to offered.optString("published_at", ""),
                "download_url" to downloadUrl,
                "url" to downloadUrl,
                "version" to releaseUuid,
                "current_version" to releaseUuid
            ))
        } catch (e: Exception) {
            return unavailable(version, e.message ?: "check failed")
        } finally {
            connection.disconnect()
        }
    }
}
