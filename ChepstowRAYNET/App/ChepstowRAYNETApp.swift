import SwiftUI

@MainActor
final class AppEnvironment: ObservableObject {
    let cache: OfflineCache
    let auth: AuthStore
    init() {
        let cache = OfflineCache()
        self.cache = cache
        self.auth = AuthStore(cache: cache)
    }
}

@MainActor
@main
struct ChepstowRAYNETApp: App {
    @StateObject private var environment = AppEnvironment()
    @StateObject private var position = LocationService()
    @StateObject private var lookups = LocationLookupService()

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(environment.auth)
                .environmentObject(environment.cache)
                .environmentObject(position)
                .environmentObject(lookups)
                .tint(Brand.teal)
                .preferredColorScheme(.dark)
        }
    }
}
