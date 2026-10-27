import SwiftUI

struct SettingsView: View {
    @EnvironmentObject private var auth: AuthStore
    @EnvironmentObject private var cache: OfflineCache
    @State private var w3wKey = ""
    @State private var keySaved = false
    @State private var message: String?
    @State private var loggingOut = false

    var body: some View {
        Form {
            Section("Account") {
                HStack {
                    Image("RaynetLogo").resizable().scaledToFit().frame(width: 55, height: 55)
                    VStack(alignment: .leading) {
                        Text(auth.user?.name ?? "Member").font(.headline)
                        Text("\(auth.user?.callsign ?? "") · \(auth.user?.role ?? "")").font(.caption).foregroundStyle(Brand.muted)
                    }
                }
                LabeledContent("API", value: "v1 · HTTPS")
                LabeledContent("Session", value: auth.offline ? "Previously verified · offline" : "Server verified")
                Button("Verify account now") { Task { await auth.restore() } }
                    .disabled(auth.offline)
                Button("Sign out and delete cached data", role: .destructive) {
                    loggingOut = true
                    Task { await auth.logout(); loggingOut = false }
                }.disabled(loggingOut)
            }
            Section("Offline access") {
                Toggle("Keep data for offline use", isOn: $cache.enabled)
                    .onChange(of: cache.enabled) { _, enabled in
                        if enabled { auth.prepareOfflineIdentity() }
                    }
                Text("OFF by default. When enabled, fetched MIS lists and dashboards are saved locally with iOS file protection. Only previously verified accounts with unexpired tokens can access cached data offline. Offline data may be outdated, and server-side revocation cannot be checked without a connection.")
                    .font(.caption).foregroundStyle(Brand.muted)
                Text("Offline is read-only: no RSVPs, updates, uploads or acknowledgements are queued. Location is not cached. PDFs are not retained.")
                    .font(.caption).foregroundStyle(Brand.muted)
                Button("Delete downloaded data now", role: .destructive) {
                    cache.clear(); message = "Cached snapshots deleted."
                }
            }
            Section("what3words (optional external service)") {
                SecureField("Personal what3words API key", text: $w3wKey)
                    .textInputAutocapitalization(.never).autocorrectionDisabled()
                Button("Store key in iPhone Keychain") {
                    keySaved = KeychainStore.save(w3wKey, account: "what3words_api_key")
                    w3wKey = ""; message = keySaved ? "Key saved." : "Unable to save key."
                }.disabled(w3wKey.isEmpty)
                if keySaved {
                    Button("Remove API key", role: .destructive) {
                        KeychainStore.delete(account: "what3words_api_key"); keySaved = false
                    }
                }
                Text("A key is required for the exact what3words address. GPS and OS grid work without a key. Postcode lookup uses Postcodes.io.")
                    .font(.caption).foregroundStyle(Brand.muted)
            }
            Section("About") {
                LabeledContent("Server", value: "chepstow-raynet-mis.defenderonfrequency.uk")
                LabeledContent("Mobile API", value: "/api/v1")
                Text("Operational information is not a guaranteed emergency dispatch service. Use official sources and group procedures.")
                    .font(.caption).foregroundStyle(Brand.muted)
                Link("Open web portal in Safari", destination: URL(string: "https://chepstow-raynet-mis.defenderonfrequency.uk")!)
            }
            if let message { Text(message).foregroundStyle(Brand.muted) }
        }
        .scrollContentBackground(.hidden)
        .background(Brand.background)
        .navigationTitle("Settings")
        .onAppear { keySaved = KeychainStore.load(account: "what3words_api_key") != nil }
    }
}
