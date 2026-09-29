package com.nativephp.plugins.mobile_ota

import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.Handler
import android.os.Looper
import android.util.Log
import android.content.SharedPreferences
import com.nativephp.mobile.bridge.BridgeError
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.utils.NativeActionCoordinator
import com.nativephp.mobile.utils.NativeActions
import android.app.AlertDialog
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.TextView
import androidx.lifecycle.Lifecycle
import androidx.fragment.app.FragmentActivity
import org.json.JSONArray
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
                // Timeouts, so a stalled connection fails rather than holding
                // the prompt's progress dialog up forever.
                val connection = (URL(url).openConnection() as HttpURLConnection).apply {
                    connectTimeout = 15000
                    readTimeout = 30000
                }
                val bytes = try {
                    connection.inputStream.use { it.readBytes() }
                } finally {
                    connection.disconnect()
                }

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

    /**
     * Checks in the background and, when a release is waiting, asks Later /
     * Update once the activity is in the foreground. The answer goes back to
     * PHP as the event PHP named; Update is then downloaded here, behind a
     * progress dialog the user cannot dismiss, so nothing blocks the request
     * that asked.
     */
    class Prompt(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val title = parameters["title"] as? String ?: "Update available"
            val message = parameters["message"] as? String ?: ""
            val buttons = when (val raw = parameters["buttons"]) {
                is JSONArray -> (0 until raw.length()).map { raw.optString(it) }
                is List<*> -> raw.mapNotNull { it as? String }
                else -> listOf("Later", "Update")
            }.filter { it.isNotEmpty() }
            val id = parameters["id"] as? String
            val event = parameters["event"] as? String

            // Once per process. PHP boots more than once per launch (a
            // runtime reboot, the queue worker, classic mode per request) and
            // asks every time, so the answer to "have we asked" lives here.
            if (!claimPrompt()) {
                return BridgeResponse.success(mapOf("scheduled" to false, "reason" to "already asked"))
            }

            Thread {
                // Core returns bridge data unwrapped; older cores wrapped it
                // in "data". Read either so the prompt shows on both.
                val check = checkForUpdate(activity, parameters)
                val data = check["data"] as? Map<*, *> ?: check
                if (data["available"] != true) {
                    return@Thread
                }

                // Already downloaded and waiting for the next launch.
                if (pendingHolds(activity, data["release"] as? String ?: "")) {
                    return@Thread
                }

                // Never offer what the download would refuse.
                if (predatesShell(data["published_at"] as? String, parameters["shell_built_at"] as? String)) {
                    return@Thread
                }

                whenInForeground(activity, attemptsLeft = 120) {
                    NativeActions.showAlert(
                        activity,
                        title,
                        message,
                        buttons.toTypedArray(),
                        buttons.mapIndexed { index, _ -> if (index == 0) "cancel" else "default" }.toTypedArray()
                    ) { index, label ->
                        if (!event.isNullOrBlank()) {
                            val payload = JSONObject().put("index", index).put("label", label)
                            if (id != null) payload.put("id", id)
                            NativeActionCoordinator.dispatchEvent(activity, event, payload.toString())
                        }
                        // The second button takes the update, and it is
                        // fetched right here behind a progress dialog. PHP
                        // only hears how it went.
                        if (index == 1) {
                            downloadWithProgress(activity, parameters)
                        }
                    }
                }
            }.start()

            return BridgeResponse.success(mapOf("scheduled" to true))
        }
    }

    /**
     * Runs on the main thread once the activity is resumed, trying again every
     * half second. PHP boots before the activity is on screen, and a dialog
     * shown then is lost.
     */
    private fun whenInForeground(activity: FragmentActivity, attemptsLeft: Int, block: () -> Unit) {
        Handler(Looper.getMainLooper()).post {
            if (activity.isFinishing || activity.isDestroyed) return@post
            if (activity.lifecycle.currentState.isAtLeast(Lifecycle.State.RESUMED)) {
                try {
                    block()
                } catch (e: Exception) {
                    Log.e("OtaFunctions.Prompt", "Could not show the update prompt: ${e.message}", e)
                }
                return@post
            }
            if (attemptsLeft > 0) {
                Handler(Looper.getMainLooper()).postDelayed({
                    whenInForeground(activity, attemptsLeft - 1, block)
                }, 500)
            }
        }
    }

    /**
     * Update was tapped (main thread): show a progress dialog that cannot be
     * cancelled, download the release, then dismiss it and say how it went,
     * to the user only when it failed and to PHP either way.
     */
    private fun downloadWithProgress(activity: FragmentActivity, parameters: Map<String, Any>) {
        val progress = parameters["progress"] as? String ?: "Downloading update…"
        val failedTitle = parameters["failed_title"] as? String ?: "Couldn't download the update"
        val downloadedEvent = parameters["downloaded_event"] as? String
        val failedEvent = parameters["failed_event"] as? String

        val dialog = try {
            progressDialog(activity, progress).also { it.show() }
        } catch (e: Exception) {
            Log.e("OtaFunctions.Prompt", "Could not show download progress: ${e.message}", e)
            null
        }

        Thread {
            val result = runCatching { fetchOffered(activity, parameters) }
                .getOrElse { mapOf("success" to false, "error" to (it.message ?: "Download failed.")) }
            val succeeded = result["success"] == true
            val reason = failureReason(result)

            Handler(Looper.getMainLooper()).post {
                runCatching { dialog?.dismiss() }
                if (succeeded) {
                    if (!downloadedEvent.isNullOrBlank()) {
                        NativeActionCoordinator.dispatchEvent(
                            activity,
                            downloadedEvent,
                            JSONObject().put("version", result["version"] as? String ?: "").toString()
                        )
                    }
                    return@post
                }
                if (!failedEvent.isNullOrBlank()) {
                    NativeActionCoordinator.dispatchEvent(
                        activity,
                        failedEvent,
                        JSONObject().put("stage", "download").put("message", reason).toString()
                    )
                }
                if (!activity.isFinishing && !activity.isDestroyed) {
                    runCatching {
                        AlertDialog.Builder(activity)
                            .setTitle(failedTitle)
                            .setMessage(reason)
                            .setPositiveButton("OK", null)
                            .show()
                    }
                }
            }
        }.start()
    }

    private fun progressDialog(activity: FragmentActivity, message: String): AlertDialog {
        val density = activity.resources.displayMetrics.density
        val padding = (24 * density).toInt()
        val layout = LinearLayout(activity).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = android.view.Gravity.CENTER_VERTICAL
            setPadding(padding, padding, padding, padding)
            addView(ProgressBar(activity).apply { isIndeterminate = true })
            addView(TextView(activity).apply {
                text = message
                textSize = 16f
                setPadding(padding, 0, 0, 0)
            })
        }
        return AlertDialog.Builder(activity)
            .setView(layout)
            .setCancelable(false)
            .create()
            .apply { setCanceledOnTouchOutside(false) }
    }

    /**
     * Asks the lane again, because the download URL is signed and may have
     * expired while the dialog waited for an answer, then queues what it
     * offers through Ota.Download's own code.
     */
    private fun fetchOffered(context: Context, parameters: Map<String, Any>): Map<String, Any> {
        val check = checkForUpdate(context, parameters)
        val offered = check["data"] as? Map<*, *> ?: check
        val url = offered["download_url"] as? String ?: ""
        val release = offered["release"] as? String ?: ""

        if (offered["available"] != true || url.isBlank() || release.isBlank()) {
            return mapOf("success" to false, "error" to (offered["reason"] as? String ?: "The update is no longer available."))
        }
        if (predatesShell(offered["published_at"] as? String, parameters["shell_built_at"] as? String)) {
            return mapOf("success" to false, "error" to "That release is older than the installed app.")
        }

        val downloadParameters = mutableMapOf<String, Any>(
            "url" to url,
            "version" to release,
            "release" to release
        )
        for (key in listOf("sha256", "size", "commit", "published_at")) {
            offered[key]?.let { downloadParameters[key] = it }
        }
        return Download(context).execute(downloadParameters) + ("version" to release)
    }

    private fun failureReason(result: Map<String, Any>): String =
        result["error"] as? String ?: result["message"] as? String ?: "Download failed."

    /**
     * A release published before this shell was built is already inside it.
     * The server applies the same rule; PHP's downloadAndApply does too.
     */
    private fun predatesShell(publishedAt: String?, builtAt: String?): Boolean {
        val published = parseDate(publishedAt) ?: return false
        val built = parseDate(builtAt) ?: return false
        return !published.isAfter(built)
    }

    private fun parseDate(value: String?): java.time.Instant? {
        if (value.isNullOrBlank()) return null
        return try {
            java.time.OffsetDateTime.parse(value).toInstant()
        } catch (_: Exception) {
            null
        }
    }

    private val prompted = java.util.concurrent.atomic.AtomicBoolean(false)

    /** True the first time it is called in this process, false after. */
    private fun claimPrompt(): Boolean = prompted.compareAndSet(false, true)

    /**
     * Whether the queued payload is already this release: pending.zip is there
     * and the pending.json written after it names the same release.
     */
    private fun pendingHolds(context: Context, release: String): Boolean {
        if (release.isEmpty() || !pendingZip(context).isFile) return false
        val manifest = pendingManifest(context)
        if (!manifest.isFile) return false
        return try {
            JSONObject(manifest.readText()).optString("release_uuid") == release
        } catch (_: Exception) {
            false
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
        val builtAt = (parameters["shell_built_at"] as? String)?.takeIf { it.isNotBlank() }
        val baseline = builtAt?.let { "&shell_built_at=" + java.net.URLEncoder.encode(it, "UTF-8") } ?: ""
        val url = "$trimmed/api/v1/apps/$project/$arc/$fingerprint$held?algorithm=$algorithm$baseline"
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
