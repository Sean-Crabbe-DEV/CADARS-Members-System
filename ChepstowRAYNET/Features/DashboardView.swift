import SwiftUI
import CoreLocation
import UIKit

struct DashboardView: View {
    @EnvironmentObject private var auth: AuthStore
    @EnvironmentObject private var location: LocationService
    @EnvironmentObject private var lookups: LocationLookupService
    @State private var dashboard: DashboardReply?
    @State private var activationSummary: ActivationInbox?
    @State private var errorMessage: String?
    private let utcClockFormat = Date.FormatStyle(
        date: .omitted, time: .omitted,
        locale: Locale(identifier: "en_GB"),
        timeZone: TimeZone(secondsFromGMT: 0)!
    ).hour(.twoDigits(amPM: .omitted)).minute(.twoDigits).second(.twoDigits)

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                BrandCard {
                    HStack(spacing: 12) {
                        Image("RaynetLogo").resizable().scaledToFit().frame(width: 64, height: 64)
                        VStack(alignment: .leading) {
                            Text("Chepstow RAYNET").font(.title2.bold())
                            Text(auth.user?.name ?? "Member").foregroundStyle(Brand.muted)
                            if auth.offline { Label("OFFLINE · cached read-only", systemImage: "wifi.slash").font(.caption).foregroundStyle(Brand.amber) }
                            else { Label("Connected to MIS API v1", systemImage: "checkmark.shield").font(.caption).foregroundStyle(Brand.teal) }
                        }
                    }
                    TimelineView(.periodic(from: .now, by: 1)) { context in
                        HStack(alignment: .firstTextBaseline) {
                            Text(context.date, format: utcClockFormat).font(.system(.title, design: .monospaced).weight(.semibold)).monospacedDigit()
                            Text("UTC").font(.caption.bold()).foregroundStyle(Brand.muted)
                        }
                    }
                }
                BrandCard {
                    HStack {
                        Text("Activation status").font(.headline)
                        Spacer()
                        NavigationLink { ActivationsView() } label: { Label("Open inbox", systemImage: "bell") }
                    }
                    if let summary = activationSummary {
                        Text("Unanswered: \(summary.unansweredCount)")
                            .font(.title3.bold()).foregroundStyle(summary.unansweredCount > 0 ? Brand.amber : Brand.teal)
                        if let recent = summary.activations.first {
                            Text(recent.title).font(.subheadline)
                            Text("\(recent.priority) · \(recent.status) · \(recent.response ?? "Pending")")
                                .font(.caption).foregroundStyle(Brand.muted)
                        }
                    } else {
                        StatusText(message: "Activation status not yet available. This is not a dispatch notification.")
                    }
                    Text("Alerts refresh while the inbox is open. No server push when the app is closed.")
                        .font(.caption).foregroundStyle(Brand.muted)
                }
                BrandCard {
                    HStack {
                        Text("Current position").font(.headline)
                        Spacer()
                        Button { location.requestPosition() } label: { Label("Locate", systemImage: "location.fill") }.buttonStyle(.bordered)
                    }
                    if let p = location.location {
                        line("Latitude", String(format: "%.6f°", p.coordinate.latitude))
                        line("Longitude", String(format: "%.6f°", p.coordinate.longitude))
                        if let grid = BritishNationalGrid.convert(latitude: p.coordinate.latitude, longitude: p.coordinate.longitude) {
                            line("OS Grid (approx.)", grid.gridReference)
                            line("Easting / Northing", "\(grid.easting) / \(grid.northing) m")
                        } else { line("OS Grid", "Outside GB grid") }
                        line("Accuracy", String(format: "±%.0f m", p.horizontalAccuracy))
                        line("Measured", p.timestamp.formatted(date: .abbreviated, time: .standard))
                        line("Nearest postcode", lookups.nearestPostcode ?? "Not looked up")
                        line("what3words", lookups.threeWordAddress ?? "Not looked up")
                        HStack {
                            Button { UIPasteboard.general.string = positionReport(p) } label: { Label("Copy", systemImage: "doc.on.doc") }
                                .buttonStyle(.bordered)
                            Spacer()
                            Button { Task { await lookups.lookup(coordinate: p.coordinate) } } label: {
                                Label("Lookup postcode / w3w", systemImage: "magnifyingglass")
                            }.buttonStyle(.borderedProminent).disabled(lookups.loading || auth.offline)
                        }
                        if KeychainStore.load(account: "what3words_api_key")?.isEmpty != false {
                            Button {
                                if let url = URL(string: "w3w://show?currentlocation") {
                                    UIApplication.shared.open(url, options: [:]) { opened in
                                        if !opened, let website = URL(string: "https://what3words.com") {
                                            UIApplication.shared.open(website)
                                        }
                                    }
                                }
                            } label: {
                                Label("Open current location in what3words", systemImage: "arrow.up.right.square")
                            }.buttonStyle(.bordered)
                            StatusText(message: "Opens the official what3words service; the words cannot be retrieved by this app without an API key.")
                        }
                        StatusText(message: "Grid approximate (Helmert), not OSTN15. Postcode is a nearby postcode centroid. External lookups send coordinates only when requested.")
                        if let status = lookups.status { StatusText(message: status) }
                    } else {
                        Text("Location access needed to show GPS and OS grid.").foregroundStyle(Brand.muted)
                        if let error = location.errorMessage { StatusText(message: error) }
                    }
                }
                BrandCard {
                    HStack {
                        Text("My MIS overview").font(.headline)
                        Spacer()
                        Button { Task { await refresh() } } label: { Image(systemName: "arrow.clockwise") }
                    }
                    if let d = dashboard {
                        line("Training verified", "\(d.trainingVerified) / \(d.trainingRequired)")
                        line("Verified experience", String(format: "%.1f hours", d.verifiedExperienceHours))
                        Text("Upcoming events").font(.subheadline.bold()).foregroundStyle(Brand.teal)
                        if d.upcomingEvents.isEmpty { StatusText(message: "No upcoming events returned.") }
                        ForEach(d.upcomingEvents) { event in
                            VStack(alignment: .leading) {
                                Text(event.title).font(.subheadline.bold())
                                Text("\(event.day) · \(event.startTime ?? "TBC") · \(event.location ?? "")")
                                    .font(.caption).foregroundStyle(Brand.muted)
                            }
                        }
                    } else if let errorMessage { StatusText(message: errorMessage) }
                }
            }.padding()
        }
        .background(Brand.background)
        .navigationTitle("Dashboard")
        .task { await refresh(); location.requestPosition() }
        .onChange(of: location.location?.timestamp) { _, _ in
            if let p = location.location { lookups.reset(for: p) }
        }
    }
    private func refresh() async {
        do { dashboard = try await auth.get("dashboard", as: DashboardReply.self); errorMessage = nil }
        catch { errorMessage = error.localizedDescription }
        do { activationSummary = try await auth.get("my/activations", as: ActivationInbox.self) }
        catch { /* Preserve overview if the newer operations API is not deployed. */ }
    }
    private func line(_ label: String, _ value: String) -> some View {
        HStack(alignment: .firstTextBaseline) {
            Text(label).foregroundStyle(Brand.muted)
            Spacer(minLength: 8)
            Text(value).multilineTextAlignment(.trailing).textSelection(.enabled)
        }.font(.subheadline)
    }
    private func positionReport(_ p: CLLocation) -> String {
        var rows = ["Chepstow RAYNET position", String(format: "WGS84: %.6f, %.6f",p.coordinate.latitude,p.coordinate.longitude),
                    "GPS accuracy: ±\(Int(p.horizontalAccuracy.rounded())) m", "UTC date/time: \(p.timestamp.formatted(.iso8601))"]
        if let grid = BritishNationalGrid.convert(latitude: p.coordinate.latitude, longitude: p.coordinate.longitude) { rows.append("OS grid (approx.): \(grid.gridReference)") }
        if let postcode = lookups.nearestPostcode { rows.append("Nearest postcode: \(postcode)") }
        if let w = lookups.threeWordAddress { rows.append("what3words: \(w)") }
        return rows.joined(separator: "\n")
    }
}
