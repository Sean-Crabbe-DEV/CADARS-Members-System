import Foundation
import Combine
import CryptoKit

/// Opt-in read-only snapshots. Never stores tokens, passwords, or GPS location.
@MainActor
final class OfflineCache: ObservableObject {
    @Published var enabled: Bool {
        didSet {
            UserDefaults.standard.set(enabled, forKey: "raynetOfflineCacheEnabled")
            if !enabled { clear(); KeychainStore.delete(account: "verified_member") }
        }
    }
    init() { enabled = UserDefaults.standard.bool(forKey: "raynetOfflineCacheEnabled") }

    private var directory: URL? {
        guard let base = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first else { return nil }
        return base.appendingPathComponent("OfflineSnapshots", isDirectory: true)
    }
    private func file(for key: String, userID: Int) -> URL? {
        guard let directory else { return nil }
        let text = "\(userID):\(key)"
        let digest = SHA256.hash(data: Data(text.utf8)).map { String(format: "%02x", $0) }.joined()
        return directory.appendingPathComponent("\(digest).json")
    }
    func save(_ data: Data, key: String, userID: Int) {
        guard enabled, let directory, let file = file(for: key, userID: userID) else { return }
        do {
            try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
            try data.write(to: file, options: [.atomic, .completeFileProtection])
            var values = URLResourceValues(); values.isExcludedFromBackup = true
            var fileToConfigure = file
            try? fileToConfigure.setResourceValues(values)
        } catch { /* Never block a successful live request because caching failed. */ }
    }
    func load(key: String, userID: Int) -> Data? {
        guard enabled, let file = file(for: key, userID: userID) else { return nil }
        return try? Data(contentsOf: file)
    }
    func remove(key: String, userID: Int) {
        guard let file = file(for: key, userID: userID) else { return }
        try? FileManager.default.removeItem(at: file)
    }
    func clear() {
        guard let directory else { return }
        try? FileManager.default.removeItem(at: directory)
    }
}
