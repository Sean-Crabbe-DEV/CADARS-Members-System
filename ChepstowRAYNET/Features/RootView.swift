import SwiftUI

struct RootView: View {
    @EnvironmentObject private var auth: AuthStore
    @EnvironmentObject private var location: LocationService
    @EnvironmentObject private var lookup: LocationLookupService
    @Environment(\.scenePhase) private var phase
    @State private var selectedTab = 0

    var body: some View {
        Group {
            switch auth.state {
            case .checking:
                VStack(spacing: 15) {
                    Image("RaynetLogo").resizable().scaledToFit().frame(width: 140, height: 140)
                    ProgressView("Checking your MIS account…")
                }
            case .signedOut:
                NavigationStack { NativeLoginView() }
            case .online, .offline:
                TabView(selection: $selectedTab) {
                    NavigationStack { DashboardView() }
                        .tabItem { Label("Dashboard", systemImage: "house.fill") }.tag(0)
                    NavigationStack { ActivationsView() }
                        .tabItem { Label("Alerts", systemImage: "bell.badge") }.tag(1)
                    NavigationStack { OperationsView() }
                        .tabItem { Label("Operations", systemImage: "antenna.radiowaves.left.and.right") }.tag(2)
                    NavigationStack { OperationalMapView() }
                        .tabItem { Label("Map", systemImage: "map") }.tag(3)
                    NavigationStack { SettingsView() }
                        .tabItem { Label("Settings", systemImage: "gearshape") }.tag(4)
                }
            }
        }
        .background(Brand.background.ignoresSafeArea())
        .task { if auth.state == .checking { await auth.restore() } }
        .onChange(of: auth.authenticated) { _, signedIn in
            if !signedIn { selectedTab = 0; location.clearOnLock(); lookup.clear() }
        }
        .onChange(of: phase) { _, newPhase in
            // Revalidate bearer when returning online, including revoked/expired credentials.
            if newPhase == .active && auth.authenticated { Task { await auth.restore() } }
        }
    }
}

struct NativeLoginView: View {
    @EnvironmentObject private var auth: AuthStore
    @State private var username = ""
    @State private var password = ""
    var body: some View {
        ScrollView {
            VStack(spacing: 20) {
                Image("RaynetLogo").resizable().scaledToFit().frame(width: 180, height: 180)
                VStack(spacing: 5) {
                    Text("CHEPSTOW RAYNET").font(.title2.bold())
                    Text("MEMBERS PORTAL · NATIVE APP").font(.caption).foregroundStyle(Brand.teal)
                }
                BrandCard {
                    Text("Sign in with your MIS account").font(.headline)
                    TextField("Portal username", text: $username)
                        .textContentType(.username).textInputAutocapitalization(.never)
                        .autocorrectionDisabled().padding(12).background(Brand.surface2, in: RoundedRectangle(cornerRadius: 8))
                    SecureField("Password", text: $password)
                        .textContentType(.password).padding(12).background(Brand.surface2, in: RoundedRectangle(cornerRadius: 8))
                    Button {
                        let user = username.trimmingCharacters(in: .whitespacesAndNewlines)
                        let pass = password; password = ""
                        Task { await auth.login(username: user, password: pass) }
                    } label: {
                        Label("Sign in", systemImage: "lock.open")
                            .frame(maxWidth: .infinity).padding(.vertical, 8)
                    }.buttonStyle(.borderedProminent)
                        .disabled(username.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || password.isEmpty)
                    if let message = auth.message { Text(message).font(.footnote).foregroundStyle(Brand.amber) }
                }
                StatusText(message: "Existing portal credentials only. Passwords are never saved by this app. Network authentication is required for the first sign-in.")
            }.padding(24)
        }.background(Brand.background).navigationTitle("Member login")
    }
}
