package com.nativephp.plugins.mobile_ota

import android.app.AlertDialog
import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.content.SharedPreferences
import androidx.fragment.app.FragmentActivity
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
                ?: return BridgeResponse.error("url is required")
            return runCatching {
                backupPendingIfPresent(context)
                val pending = pendingZip(context)
                pending.outputStream().use { out ->
                    URL(url).openStream().use { it.copyTo(out) }
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
            }.getOrElse { BridgeResponse.error(it.message ?: "download failed") }
        }
    }

    class Apply(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val pending = pendingZip(context)
            if (!pending.exists()) {
                return BridgeResponse.error("no pending payload")
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
                return BridgeResponse.error("no previous payload")
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
        val version = versionParam(parameters, context)
        if (endpoint.isNullOrBlank() || project.isNullOrBlank()) {
            return unavailable(version, "missing endpoint or project")
        }
        if (!isOnline(context)) {
            return unavailable(version, "offline")
        }
        val trimmed = endpoint.trimEnd('/')
        val url = "$trimmed/api/apps/$project/ota?version=$version"
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
            val upToDate = parseUpToDate(body.opt("upToDate"))
                ?: return unavailable(version, "json missing")
            val currentVersion = if (body.has("current_version") && !body.isNull("current_version")) {
                body.optString("current_version", "")
            } else {
                ""
            }
            val downloadUrl = if (body.has("download_url") && !body.isNull("download_url")) {
                body.optString("download_url", "")
            } else {
                ""
            }
            val available = !upToDate && downloadUrl.isNotBlank()
            return BridgeResponse.success(mapOf(
                "available" to available,
                "upToDate" to upToDate,
                "current_version" to currentVersion,
                "download_url" to downloadUrl,
                "version" to currentVersion,
                "url" to downloadUrl
            ))
        } catch (e: Exception) {
            return unavailable(version, e.message ?: "check failed")
        } finally {
            connection.disconnect()
        }
    }
}
