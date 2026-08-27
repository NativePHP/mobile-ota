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
                try body.write(to: OtaFunctions.pendingZip, options: .atomic)
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

    class Prompt: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let check = try checkForUpdate(parameters: parameters)
            let data = check["data"] as? [String: Any]
            let available = data?["available"] as? Bool ?? false
            if !available {
                return BridgeResponse.success(data: ["available": false, "accepted": false])
            }
            let currentVersion = data?["current_version"] as? String
                ?? data?["version"] as? String
                ?? ""
            return BridgeResponse.success(data: [
                "available": true,
                "accepted": false,
                "version": currentVersion,
                "current_version": currentVersion,
                "download_url": data?["download_url"] ?? "",
                "url": data?["url"] ?? data?["download_url"] ?? "",
                "needsUi": true
            ])
        }
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
        let version = versionString(from: parameters) ?? installedVersion
        if endpoint.isEmpty || project.isEmpty {
            return unavailable(version: version, reason: "missing endpoint or project")
        }
        if !isOnline() {
            return unavailable(version: version, reason: "offline")
        }
        let trimmed = endpoint.trimmingCharacters(in: CharacterSet(charactersIn: "/"))
        guard let url = URL(string: "\(trimmed)/api/apps/\(project)/ota?version=\(version)") else {
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
        if let requestError {
            return unavailable(version: version, reason: requestError.localizedDescription)
        }
        if statusCode < 200 || statusCode >= 300 {
            return unavailable(version: version, reason: "http \(statusCode)")
        }
        if !parsedJson {
            return unavailable(version: version, reason: "json missing")
        }
        guard let upToDate = parseUpToDate(body["upToDate"]) else {
            return unavailable(version: version, reason: "json missing")
        }
        let currentVersion = body["current_version"] as? String ?? ""
        let downloadUrl = body["download_url"] as? String ?? ""
        let available = (upToDate == false) && !downloadUrl.isEmpty
        return BridgeResponse.success(data: [
            "available": available,
            "upToDate": upToDate,
            "current_version": currentVersion,
            "download_url": downloadUrl,
            "version": currentVersion,
            "url": downloadUrl
        ])
    }
}
