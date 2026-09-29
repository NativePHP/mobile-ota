import CryptoKit
import Foundation
import Network
import UIKit

enum OtaFunctions {
    private static let versionKey = "nativephp.ota.version"

    /// Plugin-owned backup of the last pending zip. Must NOT live under
    /// Documents/updates — core applies any *.zip there on the next boot.
    private static var otaDirectory: URL {
        let appSupport = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first!
        let dir = appSupport.appendingPathComponent("ota", isDirectory: true)
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        return dir
    }

    private static var previousZip: URL { otaDirectory.appendingPathComponent("previous.zip") }

    /// Core pending location: Documents/updates/pending.zip
    private static var updatesDirectory: URL {
        let docs = FileManager.default.urls(for: .documentDirectory, in: .userDomainMask).first!
        let dir = docs.appendingPathComponent("updates", isDirectory: true)
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        return dir
    }

    private static var pendingZip: URL { updatesDirectory.appendingPathComponent("pending.zip") }

    /// Written after the zip, so its absence marks an interrupted download.
    private static var pendingManifest: URL { updatesDirectory.appendingPathComponent("pending.json") }

    private static var installedVersion: String {
        get {
            if let s = UserDefaults.standard.string(forKey: versionKey), !s.isEmpty {
                return s
            }
            return String(UserDefaults.standard.integer(forKey: versionKey))
        }
        set { UserDefaults.standard.set(newValue, forKey: versionKey) }
    }

    /// Copy the current pending zip aside before it is overwritten so rollback
    /// can re-drop it as pending for the next core boot.
    private static func backupPendingIfPresent() throws {
        let fm = FileManager.default
        guard fm.fileExists(atPath: pendingZip.path) else { return }
        if fm.fileExists(atPath: previousZip.path) {
            try fm.removeItem(at: previousZip)
        }
        try fm.copyItem(at: pendingZip, to: previousZip)
    }

    class Check: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            return try checkForUpdate(parameters: parameters)
        }
    }

    class Download: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let urlString = parameters["url"] as? String, let url = URL(string: urlString) else {
                return [
                    "success": false,
                    "error": "url is required"
                ]
            }
            do {
                try OtaFunctions.backupPendingIfPresent()
                var request = URLRequest(url: url)
                request.httpMethod = "GET"
                request.timeoutInterval = 120
                let semaphore = DispatchSemaphore(value: 0)
                var body: Data?
                var statusCode = 0
                var requestError: Error?
                URLSession.shared.dataTask(with: request) { data, response, error in
                    body = data
                    statusCode = (response as? HTTPURLResponse)?.statusCode ?? 0
                    requestError = error
                    semaphore.signal()
                }.resume()
                _ = semaphore.wait(timeout: .now() + 120)
                if let requestError {
                    return [
                        "success": false,
                        "error": requestError.localizedDescription
                    ]
                }
                guard statusCode == 200, let body, !body.isEmpty else {
                    let snippet = body.flatMap { String(data: $0.prefix(180), encoding: .utf8) } ?? ""
                    return [
                        "success": false,
                        "error": "download failed (HTTP \(statusCode)) \(snippet)"
                    ]
                }
                // A payload that does not match what the server described is
                // not the release we were offered, so it never reaches the
                // location core extracts from.
                if let expected = parameters["sha256"] as? String, !expected.isEmpty {
                    let actual = SHA256.hash(data: body).map { String(format: "%02x", $0) }.joined()
                    if actual != expected.lowercased() {
                        return ["success": false, "error": "checksum mismatch"]
                    }
                }
                if let expected = (parameters["size"] as? NSNumber)?.intValue, expected > 0, body.count != expected {
                    return ["success": false, "error": "size mismatch: expected \(expected), got \(body.count)"]
                }

                try body.write(to: OtaFunctions.pendingZip, options: .atomic)

                // What the server said about these bytes, written after them:
                // core treats its absence as an interrupted download, and moves
                // it into the app as ota.json once the payload is applied. The
                // signed download URL is deliberately not persisted.
                if let release = parameters["release"] as? String, !release.isEmpty {
                    var manifest: [String: Any] = ["release_uuid": release]
                    for key in ["sha256", "size", "commit", "published_at", "arc", "shell_fingerprint"] {
                        if let value = parameters[key], !(value is NSNull) {
                            manifest[key] = value
                        }
                    }
                    if let data = try? JSONSerialization.data(withJSONObject: manifest, options: [.prettyPrinted, .sortedKeys]) {
                        try? data.write(to: OtaFunctions.pendingManifest, options: .atomic)
                    }
                }
                if let version = OtaFunctions.versionString(from: parameters) {
                    OtaFunctions.installedVersion = version
                }
                return BridgeResponse.success(data: [
                    "success": true,
                    "path": OtaFunctions.pendingZip.path,
                    "queued": true,
                    "applyOnNextBoot": true
                ])
            } catch {
                return [
                    "success": false,
                    "error": error.localizedDescription
                ]
            }
        }
    }

    class Apply: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let fm = FileManager.default
            guard fm.fileExists(atPath: OtaFunctions.pendingZip.path) else {
                return BridgeResponse.error(code: "ota", message: "no pending payload")
            }
            let version = OtaFunctions.versionString(from: parameters) ?? OtaFunctions.installedVersion
            OtaFunctions.installedVersion = version
            // Core extracts Documents/updates/*.zip on the next boot.
            // Do not unzip into the running Laravel tree from the plugin.
            return BridgeResponse.success(data: [
                "success": true,
                "version": version,
                "current_version": version,
                "queued": true,
                "applyOnNextBoot": true,
                "restartRequired": true
            ])
        }
    }

    class Rollback: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let fm = FileManager.default
            guard fm.fileExists(atPath: OtaFunctions.previousZip.path) else {
                return BridgeResponse.error(code: "ota", message: "no previous payload")
            }
            let swap = OtaFunctions.otaDirectory.appendingPathComponent("swap.zip")
            if fm.fileExists(atPath: OtaFunctions.pendingZip.path) {
                if fm.fileExists(atPath: swap.path) {
                    try fm.removeItem(at: swap)
                }
                try fm.copyItem(at: OtaFunctions.pendingZip, to: swap)
            }
            if fm.fileExists(atPath: OtaFunctions.pendingZip.path) {
                try fm.removeItem(at: OtaFunctions.pendingZip)
            }
            try fm.copyItem(at: OtaFunctions.previousZip, to: OtaFunctions.pendingZip)
            if fm.fileExists(atPath: swap.path) {
                if fm.fileExists(atPath: OtaFunctions.previousZip.path) {
                    try fm.removeItem(at: OtaFunctions.previousZip)
                }
                try fm.copyItem(at: swap, to: OtaFunctions.previousZip)
                try fm.removeItem(at: swap)
            }
            return BridgeResponse.success(data: [
                "success": true,
                "version": OtaFunctions.installedVersion,
                "current_version": OtaFunctions.installedVersion,
                "queued": true,
                "applyOnNextBoot": true,
                "restartRequired": true
            ])
        }
    }

    class GetStatus: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let fm = FileManager.default
            let pending = fm.fileExists(atPath: OtaFunctions.pendingZip.path)
            return BridgeResponse.success(data: [
                "version": OtaFunctions.installedVersion,
                "current_version": OtaFunctions.installedVersion,
                "hasPrevious": fm.fileExists(atPath: OtaFunctions.previousZip.path),
                "pending": pending,
                "queued": pending,
                "applyOnNextBoot": pending
            ])
        }
    }

    /// Checks in the background and, when a release is waiting, asks Later /
    /// Update once the app is actually on screen. PHP boots before that, and
    /// an alert presented while the scene is still launching is dropped, so
    /// the dialog waits for an active scene rather than presenting blind. The
    /// answer goes back to PHP as the event PHP named; Update is then
    /// downloaded here, behind a progress screen the user cannot dismiss.
    class Prompt: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let title = parameters["title"] as? String ?? "Update available"
            let message = parameters["message"] as? String ?? ""
            let buttons = (parameters["buttons"] as? [Any])?.compactMap { $0 as? String } ?? ["Later", "Update"]
            let id = parameters["id"] as? String
            let event = parameters["event"] as? String ?? ""

            // Once per process. PHP boots more than once per launch (a
            // runtime reboot, the queue worker, classic mode per request) and
            // asks every time, so the answer to "have we asked" lives here.
            guard OtaFunctions.claimPrompt() else {
                return BridgeResponse.success(data: ["scheduled": false, "reason": "already asked"])
            }

            DispatchQueue.global(qos: .utility).async {
                // Core returns bridge data unwrapped; older cores wrapped it
                // in "data". Read either so the prompt shows on both.
                guard let check = try? OtaFunctions.checkForUpdate(parameters: parameters) else {
                    return
                }
                let data = (check["data"] as? [String: Any]) ?? check
                guard data["available"] as? Bool == true else {
                    return
                }

                // Already downloaded and waiting for the next launch.
                if OtaFunctions.pendingHolds(release: data["release"] as? String ?? "") {
                    return
                }

                // Never offer what the download would refuse.
                if OtaFunctions.predatesShell(publishedAt: data["published_at"] as? String,
                                              builtAt: parameters["shell_built_at"] as? String) {
                    return
                }

                OtaFunctions.presentWhenOnScreen(attemptsLeft: 120) {
                    let alert = UIAlertController(title: title, message: message, preferredStyle: .alert)
                    for (index, label) in buttons.enumerated() {
                        alert.addAction(UIAlertAction(title: label, style: index == 0 ? .cancel : .default) { _ in
                            if !event.isEmpty {
                                var payload: [String: Any] = ["index": index, "label": label]
                                if let id { payload["id"] = id }
                                LaravelBridge.shared.send?(event, payload)
                            }
                            // The second button takes the update, and it is
                            // fetched right here with a blocking progress
                            // screen. PHP only hears how it went.
                            if index == 1 {
                                OtaFunctions.downloadWithProgress(parameters: parameters)
                            }
                        })
                    }
                    return alert
                }
            }

            return BridgeResponse.success(data: ["scheduled": true])
        }
    }

    private static let promptLock = NSLock()
    private static var prompted = false

    /// True the first time it is called in this process, false after.
    fileprivate static func claimPrompt() -> Bool {
        promptLock.lock()
        defer { promptLock.unlock() }
        if prompted { return false }
        prompted = true
        return true
    }

    /// Whether the queued payload is already this release: pending.zip is
    /// there and the pending.json written after it names the same release.
    fileprivate static func pendingHolds(release: String) -> Bool {
        guard !release.isEmpty,
              FileManager.default.fileExists(atPath: pendingZip.path),
              let data = try? Data(contentsOf: pendingManifest),
              let manifest = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
            return false
        }
        return manifest["release_uuid"] as? String == release
    }

    /// Presents once there is an active scene with a key window and nothing
    /// else being presented or dismissed, trying again every half second.
    fileprivate static func presentWhenOnScreen(
        attemptsLeft: Int,
        _ build: @escaping () -> UIViewController,
        presented: ((UIViewController) -> Void)? = nil,
        gaveUp: (() -> Void)? = nil
    ) {
        DispatchQueue.main.async {
            if let top = topViewController() {
                let controller = build()
                top.present(controller, animated: true) { presented?(controller) }
                return
            }
            guard attemptsLeft > 0 else {
                gaveUp?()
                return
            }
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.5) {
                presentWhenOnScreen(attemptsLeft: attemptsLeft - 1, build, presented: presented, gaveUp: gaveUp)
            }
        }
    }

    /// Update was tapped: cover the app with a progress screen, download the
    /// release, then take the screen away and say how it went, to the user
    /// only when it failed and to PHP either way. The download starts once
    /// the screen is up, so it can never finish before there is anything to
    /// dismiss.
    fileprivate static func downloadWithProgress(parameters: [String: Any]) {
        let progress = parameters["progress"] as? String ?? "Downloading update…"
        let failedTitle = parameters["failed_title"] as? String ?? "Couldn't download the update"
        let downloadedEvent = parameters["downloaded_event"] as? String ?? ""
        let failedEvent = parameters["failed_event"] as? String ?? ""

        func run(_ finish: @escaping ([String: Any]) -> Void) {
            DispatchQueue.global(qos: .userInitiated).async {
                let result = fetchOffered(parameters: parameters)
                if result["success"] as? Bool == true {
                    if !downloadedEvent.isEmpty {
                        LaravelBridge.shared.send?(downloadedEvent, ["version": result["version"] as? String ?? ""])
                    }
                } else if !failedEvent.isEmpty {
                    LaravelBridge.shared.send?(failedEvent, ["stage": "download", "message": failureReason(result)])
                }
                finish(result)
            }
        }

        presentWhenOnScreen(attemptsLeft: 20, { DownloadProgressController(message: progress) }, presented: { screen in
            run { result in
                DispatchQueue.main.async {
                    screen.dismiss(animated: true) {
                        guard result["success"] as? Bool != true else { return }
                        presentWhenOnScreen(attemptsLeft: 20) {
                            let alert = UIAlertController(title: failedTitle, message: failureReason(result), preferredStyle: .alert)
                            alert.addAction(UIAlertAction(title: "OK", style: .default))
                            return alert
                        }
                    }
                }
            }
        }, gaveUp: {
            // Nothing on screen to show progress over; the download is
            // what was asked for, so it still happens.
            run { _ in }
        })
    }

    /// The same download Ota.Download does, for the prompt's Update button.
    fileprivate static func download(parameters: [String: Any]) -> [String: Any] {
        do {
            return try Download().execute(parameters: parameters)
        } catch {
            return ["success": false, "error": error.localizedDescription]
        }
    }

    /// Asks the lane again, because the download URL is signed and may have
    /// expired while the dialog waited for an answer, then queues what it
    /// offers the same way Ota.Download does.
    fileprivate static func fetchOffered(parameters: [String: Any]) -> [String: Any] {
        let check = (try? checkForUpdate(parameters: parameters)) ?? [:]
        let offered = (check["data"] as? [String: Any]) ?? check
        let url = offered["download_url"] as? String ?? ""
        let release = offered["release"] as? String ?? ""

        guard offered["available"] as? Bool == true, !url.isEmpty, !release.isEmpty else {
            return ["success": false, "error": offered["reason"] as? String ?? "The update is no longer available."]
        }
        if predatesShell(publishedAt: offered["published_at"] as? String, builtAt: parameters["shell_built_at"] as? String) {
            return ["success": false, "error": "That release is older than the installed app."]
        }

        var result = download(parameters: [
            "url": url,
            "version": release,
            "release": release,
            "sha256": offered["sha256"] ?? "",
            "size": offered["size"] ?? 0,
            "commit": offered["commit"] ?? "",
            "published_at": offered["published_at"] ?? ""
        ])
        result["version"] = release
        return result
    }

    fileprivate static func failureReason(_ result: [String: Any]) -> String {
        return result["error"] as? String ?? result["message"] as? String ?? "Download failed."
    }

    /// A release published before this shell was built is already inside it.
    /// The server applies the same rule; PHP's downloadAndApply does too.
    fileprivate static func predatesShell(publishedAt: String?, builtAt: String?) -> Bool {
        guard let published = parseDate(publishedAt), let built = parseDate(builtAt) else {
            return false
        }
        return published <= built
    }

    fileprivate static func parseDate(_ value: String?) -> Date? {
        guard let value, !value.isEmpty else { return nil }
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        if let date = formatter.date(from: value) { return date }
        formatter.formatOptions = [.withInternetDateTime]
        return formatter.date(from: value)
    }

    fileprivate static func topViewController() -> UIViewController? {
        guard let scene = UIApplication.shared.connectedScenes
                  .compactMap({ $0 as? UIWindowScene })
                  .first(where: { $0.activationState == .foregroundActive }),
              let window = scene.windows.first(where: { $0.isKeyWindow }),
              var top = window.rootViewController else {
            return nil
        }
        while let presented = top.presentedViewController {
            top = presented
        }
        if top.isBeingDismissed || top.isBeingPresented || top is UIAlertController {
            return nil
        }
        return top
    }

    fileprivate static func versionString(from parameters: [String: Any]) -> String? {
        if let s = parameters["version"] as? String, !s.isEmpty {
            return s
        }
        if let i = parameters["version"] as? Int {
            return String(i)
        }
        if let n = parameters["version"] as? NSNumber {
            return n.stringValue
        }
        return nil
    }

    fileprivate static func parseUpToDate(_ value: Any?) -> Bool? {
        switch value {
        case let b as Bool:
            return b
        case let n as NSNumber:
            return n.boolValue
        case let i as Int:
            return i != 0
        case let s as String:
            let lowered = s.lowercased()
            if ["1", "true", "yes"].contains(lowered) { return true }
            if ["0", "false", "no"].contains(lowered) { return false }
            return nil
        default:
            return nil
        }
    }

    fileprivate static func unavailable(version: String, reason: String) -> [String: Any] {
        return BridgeResponse.success(data: [
            "available": false,
            "upToDate": true,
            "current_version": version,
            "download_url": "",
            "version": version,
            "url": "",
            "reason": reason
        ])
    }


    fileprivate static func isOnline() -> Bool {
        let monitor = NWPathMonitor()
        let queue = DispatchQueue(label: "nativephp.ota.network")
        let semaphore = DispatchSemaphore(value: 0)
        var path: NWPath?
        monitor.pathUpdateHandler = { p in
            path = p
            monitor.cancel()
            semaphore.signal()
        }
        monitor.start(queue: queue)
        _ = semaphore.wait(timeout: .now() + 1.5)
        monitor.cancel()
        return path?.status == .satisfied
    }

    fileprivate static func checkForUpdate(parameters: [String: Any]) throws -> [String: Any] {
        let endpoint = parameters["endpoint"] as? String ?? ""
        let project = parameters["project"] as? String ?? ""
        let arc = parameters["arc"] as? String ?? ""
        let fingerprint = parameters["fingerprint"] as? String ?? ""
        let algorithm = parameters["algorithm"] as? String ?? "1"
        let release = parameters["release"] as? String ?? ""
        let version = versionString(from: parameters) ?? installedVersion

        if endpoint.isEmpty || project.isEmpty || arc.isEmpty || fingerprint.isEmpty {
            return unavailable(version: version, reason: "missing endpoint, project, arc or fingerprint")
        }
        if !isOnline() {
            return unavailable(version: version, reason: "offline")
        }

        // The shell says who it is — app, arc, the fingerprint it was built
        // against — and which release it already holds. The answer is whatever
        // that lane points at.
        let trimmed = endpoint.trimmingCharacters(in: CharacterSet(charactersIn: "/"))
        var path = "\(trimmed)/api/v1/apps/\(project)/\(arc)/\(fingerprint)"
        if !release.isEmpty {
            path += "/\(release)"
        }
        path += "?algorithm=\(algorithm)"

        // What the shell already contains, so the lane does not offer it a
        // release published before its own code.
        if let builtAt = parameters["shell_built_at"] as? String,
           !builtAt.isEmpty,
           let encoded = builtAt.addingPercentEncoding(withAllowedCharacters: .alphanumerics) {
            path += "&shell_built_at=\(encoded)"
        }

        guard let url = URL(string: path) else {
            return unavailable(version: version, reason: "invalid endpoint")
        }

        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.timeoutInterval = 15
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        if let token = parameters["token"] as? String, !token.isEmpty {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }

        let semaphore = DispatchSemaphore(value: 0)
        var body: [String: Any] = [:]
        var requestError: Error?
        var statusCode = 0
        var parsedJson = false
        URLSession.shared.dataTask(with: request) { data, response, error in
            requestError = error
            if let http = response as? HTTPURLResponse {
                statusCode = http.statusCode
            }
            if let data, let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] {
                body = json
                parsedJson = true
            }
            semaphore.signal()
        }.resume()
        semaphore.wait()

        // The request is reported back either way: a failed check is nearly
        // always the URL being different from what you assumed.
        func failed(_ reason: String) -> [String: Any] {
            var payload = unavailable(version: version, reason: reason)
            payload["requested"] = url.absoluteString
            payload["status"] = statusCode
            return payload
        }

        if let requestError {
            return failed(requestError.localizedDescription)
        }
        if statusCode < 200 || statusCode >= 300 {
            return failed("http \(statusCode)")
        }
        if !parsedJson {
            return failed("json missing")
        }
        guard let upToDate = parseUpToDate(body["up_to_date"]) else {
            return failed("unexpected response shape")
        }

        let releaseBody = body["release"] as? [String: Any] ?? [:]
        let releaseUuid = releaseBody["uuid"] as? String ?? ""
        let sha256 = releaseBody["sha256"] as? String ?? ""
        let size = (releaseBody["size"] as? NSNumber)?.intValue ?? 0
        let commit = releaseBody["commit"] as? String ?? ""
        let publishedAt = releaseBody["published_at"] as? String ?? ""
        let downloadUrl = body["download_url"] as? String ?? ""
        let available = (upToDate == false) && !downloadUrl.isEmpty && !releaseUuid.isEmpty

        return BridgeResponse.success(data: [
            "available": available,
            "upToDate": upToDate,
            "requested": url.absoluteString,
            "status": statusCode,
            "release": releaseUuid,
            "sha256": sha256,
            "size": size,
            "commit": commit,
            "published_at": publishedAt,
            "download_url": downloadUrl,
            "url": downloadUrl,
            "version": releaseUuid,
            "current_version": releaseUuid
        ])
    }
}

/// Covers the app while an update downloads: a dimmed screen with a spinner
/// and a line of text, presented over everything and with nothing to tap,
/// so the user waits for it rather than carrying on in an app about to change.
fileprivate final class DownloadProgressController: UIViewController {
    private let message: String

    init(message: String) {
        self.message = message
        super.init(nibName: nil, bundle: nil)
        modalPresentationStyle = .overFullScreen
        modalTransitionStyle = .crossDissolve
        isModalInPresentation = true
    }

    required init?(coder: NSCoder) { nil }

    override func viewDidLoad() {
        super.viewDidLoad()
        view.backgroundColor = UIColor.black.withAlphaComponent(0.4)
        view.accessibilityViewIsModal = true

        let card = UIVisualEffectView(effect: UIBlurEffect(style: .systemMaterial))
        card.layer.cornerRadius = 14
        card.clipsToBounds = true
        card.translatesAutoresizingMaskIntoConstraints = false

        let spinner = UIActivityIndicatorView(style: .large)
        spinner.startAnimating()

        let label = UILabel()
        label.text = message
        label.font = .preferredFont(forTextStyle: .headline)
        label.textAlignment = .center
        label.numberOfLines = 0

        let stack = UIStackView(arrangedSubviews: [spinner, label])
        stack.axis = .vertical
        stack.alignment = .center
        stack.spacing = 12
        stack.translatesAutoresizingMaskIntoConstraints = false

        card.contentView.addSubview(stack)
        view.addSubview(card)

        NSLayoutConstraint.activate([
            card.centerXAnchor.constraint(equalTo: view.centerXAnchor),
            card.centerYAnchor.constraint(equalTo: view.centerYAnchor),
            card.widthAnchor.constraint(greaterThanOrEqualToConstant: 200),
            card.widthAnchor.constraint(lessThanOrEqualTo: view.widthAnchor, constant: -64),
            stack.topAnchor.constraint(equalTo: card.contentView.topAnchor, constant: 24),
            stack.bottomAnchor.constraint(equalTo: card.contentView.bottomAnchor, constant: -24),
            stack.leadingAnchor.constraint(equalTo: card.contentView.leadingAnchor, constant: 24),
            stack.trailingAnchor.constraint(equalTo: card.contentView.trailingAnchor, constant: -24)
        ])
    }
}
