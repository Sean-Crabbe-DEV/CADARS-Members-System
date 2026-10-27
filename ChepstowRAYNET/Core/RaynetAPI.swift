import Foundation
import Combine

private struct LoginInput: Encodable {
    let username: String
    let password: String
    let deviceName: String
}

enum APIError: LocalizedError {
    case unauthorized, forbidden, offline, invalidResponse, server(Int, String)
    var errorDescription: String? {
        switch self {
        case .unauthorized: return "Session expired or revoked. Sign in again."
        case .forbidden: return "This operation requires controller or administrator permission."
        case .offline: return "No connection. Only previously cached information is available."
        case .invalidResponse: return "Unexpected response from MIS API."
        case .server(let code, let detail): return "MIS HTTP \(code): \(detail)"
        }
    }
}
private struct ServerError: Decodable { let error: APIErrorMessage? }
private struct APIErrorMessage: Decodable { let message: String }

@MainActor
final class AuthStore: ObservableObject {
    enum State { case checking, signedOut, online, offline }
    @Published private(set) var state: State = .checking
    @Published private(set) var user: Member?
    @Published var message: String?
    let cache: OfflineCache
    let base = URL(string: "https://chepstow-raynet-mis.defenderonfrequency.uk/api/v1/")!
    private var token: String? = KeychainStore.load(account: "portal_bearer")
    private var expiry: Date?

    init(cache: OfflineCache) { self.cache = cache }
    var authenticated: Bool { state == .online || state == .offline }
    var offline: Bool { state == .offline }

    private func parseExpiry(_ text: String) -> Date? {
        ISO8601DateFormatter().date(from: text)
    }

    func restore() async {
        guard token != nil else { state = .signedOut; return }
        expiry = KeychainStore.load(account: "portal_expiry").flatMap(parseExpiry)
        if let expiry, expiry <= Date() { clearLocal(); return }
        state = .checking
        do {
            let current: Member = try await request("me", as: Member.self, allowCache: false)
            user = current
            storeOfflineIdentity(current)
            state = .online
            message = nil
        } catch APIError.unauthorized { clearLocal() }
        catch {
            // An unexpired bearer token validated earlier may unlock read-only snapshots
            // only when the member explicitly opted into offline storage.
            if cache.enabled, let saved = KeychainStore.load(account: "verified_member"),
               let data = saved.data(using: .utf8),
               let member = try? JSONDecoder.raynet().decode(Member.self, from: data),
               expiry != nil {
                user = member; state = .offline
                message = "Offline read-only mode. Identity will be revalidated when connected."
            } else {
                state = .signedOut
                message = "Cannot verify login with the server. Connect to the internet and retry."
            }
        }
    }

    func login(username: String, password: String) async {
        state = .checking; message = nil
        do {
            let response: LoginReply = try await request("auth/login", method: "POST",
                body: LoginInput(username: username, password: password, deviceName: "Chepstow RAYNET iOS"),
                as: LoginReply.self, publicRequest: true)
            guard response.tokenType.lowercased() == "bearer", response.accessToken.count == 64,
                  let newExpiry = parseExpiry(response.expiresAt) else { throw APIError.invalidResponse }
            guard KeychainStore.save(response.accessToken, account: "portal_bearer"),
                  KeychainStore.save(response.expiresAt, account: "portal_expiry") else { throw APIError.invalidResponse }
            token = response.accessToken; expiry = newExpiry
            let current: Member = try await request("me", as: Member.self, allowCache: false)
            user = current; state = .online; storeOfflineIdentity(current)
        } catch {
            clearLocal()
            message = error.localizedDescription
        }
    }
    func prepareOfflineIdentity() {
        if let user { storeOfflineIdentity(user) }
    }
    private func storeOfflineIdentity(_ member: Member) {
        guard cache.enabled else { return }
        if let data = try? JSONEncoder.raynet().encode(member),
           let value = String(data: data, encoding: .utf8) {
            _ = KeychainStore.save(value, account: "verified_member")
        }
    }
    func logout() async {
        if state == .online { let _: EmptyReply? = try? await request("auth/logout", method: "POST", as: EmptyReply.self) }
        clearLocal()
    }
    private func clearLocal() {
        token = nil; user = nil; expiry = nil; state = .signedOut
        for key in ["portal_bearer", "portal_expiry", "verified_member"] { KeychainStore.delete(account: key) }
        cache.clear()
    }

    func get<T: Codable>(_ path: String, as type: T.Type) async throws -> T {
        try await request(path, as: type, allowCache: true)
    }
    func write<T: Decodable, B: Encodable>(_ path: String, method: String,
                                           body: B, as type: T.Type) async throws -> T {
        let result: T = try await request(path, method: method, body: body, as: type)
        invalidateSnapshots(after: path)
        return result
    }
    func remove(_ path: String) async throws {
        let _: EmptyReply = try await request(path, method: "DELETE", as: EmptyReply.self)
        invalidateSnapshots(after: path)
    }
    private func invalidateSnapshots(after path: String) {
        guard let user else { return }
        // Operational responses are time-critical: stale snapshots must not
        // show as current after a successful activation, exercise or attendance write.
        // Hashed cache filenames cannot be invalidated by query-prefix, so remove
        // all local snapshots after those writes (not Keychain credentials).
        if path.hasPrefix("my/activations") || path.hasPrefix("control/activations") ||
           path.hasPrefix("control/exercises") || path.hasPrefix("attendance/") {
            cache.clear()
            return
        }
        var keys = ["dashboard"]
        if path.hasPrefix("events") { keys.append("events") }
        if path.hasPrefix("me/availability") || path.hasPrefix("team/availability") { keys += ["me/availability", "team/availability"] }
        if path.hasPrefix("me/equipment") { keys += ["me/equipment", "team/equipment"] }
        if path.hasPrefix("me/training") || path.hasPrefix("team/training") { keys += ["me/training", "team/training/pending"] }
        if path.hasPrefix("me/experience") || path.hasPrefix("team/experience") { keys += ["me/experience", "team/experience/pending"] }
        if path.hasPrefix("group-actions") { keys.append("group-actions") }
        if path == "me" { keys.append("me") }
        if path.hasPrefix("map/points") { keys.append("map/points") }
        if path.hasPrefix("my/activations") || path.hasPrefix("control/activations") {
            keys += ["my/activations", "control/activations"]
        }
        if path.hasPrefix("control/exercises") { keys.append("exercises") }
        if path.hasPrefix("attendance/") { keys.append("attendance/events") }
        for key in keys { cache.remove(key: "GET:" + key, userID: user.id) }
    }
    func sendNoBody<T: Decodable>(_ path: String, method: String, as type: T.Type) async throws -> T {
        let result: T = try await request(path, method: method, as: type)
        invalidateSnapshots(after: path)
        return result
    }
    private func request<T: Decodable, B: Encodable>(
        _ path: String, method: String = "GET", body: B? = nil, as type: T.Type,
        publicRequest: Bool = false, allowCache: Bool = false
    ) async throws -> T {
        if !publicRequest && token == nil { throw APIError.unauthorized }
        if !publicRequest && state == .offline && method != "GET" { throw APIError.offline }
        let pieces = path.split(separator: "?", maxSplits: 1, omittingEmptySubsequences: false)
        var components = URLComponents(url: base.appendingPathComponent(String(pieces[0])), resolvingAgainstBaseURL: false)
        if pieces.count > 1 { components?.percentEncodedQuery = String(pieces[1]) }
        guard let url = components?.url else { throw APIError.invalidResponse }
        var request = URLRequest(url: url, cachePolicy: .reloadIgnoringLocalCacheData, timeoutInterval: 20)
        request.httpMethod = method
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        if !publicRequest, let token { request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }
        if let body { request.setValue("application/json", forHTTPHeaderField: "Content-Type"); request.httpBody = try JSONEncoder.raynet().encode(body) }
        let cacheKey = "GET:" + path
        do {
            let (data, reply) = try await URLSession.shared.data(for: request)
            guard let http = reply as? HTTPURLResponse else { throw APIError.invalidResponse }
            if http.statusCode == 401 {
                clearLocal()
                throw APIError.unauthorized
            }
            if http.statusCode == 403 { throw APIError.forbidden }
            guard (200..<300).contains(http.statusCode) else {
                let detail = (try? JSONDecoder().decode(ServerError.self, from: data).error?.message) ?? "Request failed"
                throw APIError.server(http.statusCode, detail)
            }
            let result = try JSONDecoder.raynet().decode(type, from: data)
            if allowCache, method == "GET", let user { cache.save(data, key: cacheKey, userID: user.id) }
            if state == .offline { state = .online; message = nil }
            return result
        } catch {
            if allowCache, method == "GET", cache.enabled, let user,
               !(error is APIError),
               let data = cache.load(key: cacheKey, userID: user.id),
               let saved = try? JSONDecoder.raynet().decode(type, from: data) {
                state = .offline
                message = "Offline cached information. May be out of date."
                return saved
            }
            throw error
        }
    }
    // Convenience overloads: omit a body for GET, logout and DELETE.
    private func request<T: Decodable>(_ path: String, method: String = "GET", as type: T.Type,
                                        allowCache: Bool = false) async throws -> T {
        try await request(path, method: method, body: Optional<String>.none, as: type,
                          allowCache: allowCache)
    }
}

extension AuthStore {
    func pdfData(at path: String) async throws -> Data {
        guard !offline, let token else { throw APIError.offline }
        var req = URLRequest(url: base.appendingPathComponent(path), cachePolicy: .reloadIgnoringLocalCacheData)
        req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        req.setValue("application/pdf", forHTTPHeaderField: "Accept")
        let (data, response) = try await URLSession.shared.data(for: req)
        guard let http = response as? HTTPURLResponse else { throw APIError.invalidResponse }
        if http.statusCode == 401 { clearLocal(); throw APIError.unauthorized }
        guard http.statusCode == 200, data.starts(with: Data("%PDF".utf8)) else {
            throw APIError.server(http.statusCode, "Unable to retrieve the PDF manual")
        }
        return data
    }
}
