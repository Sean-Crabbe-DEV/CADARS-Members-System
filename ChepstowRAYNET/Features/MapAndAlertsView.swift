import SwiftUI
import MapKit

struct OperationalMapView: View {
    @EnvironmentObject private var auth: AuthStore
    @EnvironmentObject private var location: LocationService
    @State private var points: [MapPoint] = []
    @State private var incidents: [GroupIncident] = []
    @State private var powerPins: [PowerPin] = []
    @State private var floodPolygons: [OperationalPolygon] = []
    @State private var floodPins: [FloodMapPin] = []
    @State private var boundaries: [OperationalPolygon] = GeoJSONPolygons.bundled()
    @State private var weatherZones: [WeatherZone] = []
    @State private var showArea = true
    @State private var showZones = true
    @State private var showWarnings = false
    @State private var showPoints = true
    @State private var showIncidents = true
    @State private var showPower = false
    @State private var showFloods = false
    @State private var sourceDescription = "Bundled provisional boundary (approximate)"
    @State private var weatherStatus = "Enable the weather layer to request official warnings."
    @State private var powerMessage: String?
    @State private var floodMapStatus = "Enable floods to request official EA / NRW features."
    @State private var errorMessage: String?
    @State private var camera: MapCameraPosition = .region(
        MKCoordinateRegion(center: CLLocationCoordinate2D(latitude: 51.697, longitude: -2.725),
            span: MKCoordinateSpan(latitudeDelta: 0.42, longitudeDelta: 0.58)))

    var body: some View {
        Map(position: $camera) {
            UserAnnotation()
            if showArea || showZones {
                ForEach(boundaries) { shape in
                    if shape.kind == "operational_area" && showArea {
                        // Reference boundary is approximate, not a surveyed operational line.
                        MapPolygon(coordinates: shape.coordinates)
                            .foregroundStyle(mapColour(shape.colour, fallback: Brand.amber).opacity(0.10))
                            .stroke(mapColour(shape.colour, fallback: Brand.amber), lineWidth: 3)
                    } else if shape.kind != "operational_area" && showZones {
                        MapPolygon(coordinates: shape.coordinates)
                            .foregroundStyle(mapColour(shape.colour, fallback: Brand.teal).opacity(0.15))
                            .stroke(mapColour(shape.colour, fallback: Brand.teal), lineWidth: 2)
                    }
                }
            }
            if showZones {
                ForEach(boundaries.filter { $0.kind != "operational_area" }) { shape in
                    if let representative = shape.coordinates.first {
                        Annotation(shape.name, coordinate: representative) {
                            Text(shape.name).font(.caption2.bold()).padding(4)
                                .background(Brand.surface.opacity(0.85), in: RoundedRectangle(cornerRadius: 5))
                                .foregroundStyle(Brand.text)
                        }
                    }
                }
            }
            if showWarnings {
                ForEach(weatherZones) { zone in
                    MapPolygon(coordinates: zone.coordinates)
                        .foregroundStyle(warningColour(zone.severity).opacity(0.23))
                        .stroke(warningColour(zone.severity), lineWidth: 2)
                }
            }
            if showFloods {
                ForEach(floodPolygons) { polygon in
                    MapPolygon(coordinates: polygon.coordinates)
                        .foregroundStyle(Brand.amber.opacity(0.14))
                        .stroke(Brand.amber, lineWidth: 2)
                }
                ForEach(floodPins) { pin in
                    Annotation(pin.title, coordinate: pin.coordinate) {
                        Image(systemName: "water.waves").font(.title3)
                            .padding(5).background(Brand.surface, in: Circle())
                            .foregroundStyle(Brand.amber)
                    }
                }
            }
            if showPoints {
                ForEach(points) { point in
                    Annotation(point.title, coordinate: .init(latitude: point.lat, longitude: point.lon)) {
                        Image(systemName: "mappin.circle.fill").font(.title2).foregroundStyle(Brand.teal)
                    }
                }
            }
            if showIncidents {
                ForEach(incidents) { incident in
                    Annotation(incident.title, coordinate: .init(latitude: incident.latitude, longitude: incident.longitude)) {
                        Image(systemName: "exclamationmark.triangle.fill").font(.title2).foregroundStyle(Brand.amber)
                    }
                }
            }
            if showPower {
                ForEach(powerPins) { pin in
                    Annotation(pin.title, coordinate: .init(latitude: pin.latitude, longitude: pin.longitude)) {
                        Image(systemName: "bolt.circle.fill").font(.title2).foregroundStyle(Brand.amber)
                    }
                }
            }
        }
        .mapControls { MapCompass(); MapScaleView() }
        .safeAreaInset(edge: .bottom) {
            VStack(alignment: .leading, spacing: 8) {
                HStack {
                    Menu {
                        Toggle("Operational boundary", isOn: $showArea)
                        Toggle("RAYNET zones", isOn: $showZones)
                        Toggle("Met Office warning zones", isOn: $showWarnings)
                        Toggle("EA / NRW flood features", isOn: $showFloods)
                        Toggle("MIS map markers", isOn: $showPoints)
                        Toggle("Group incidents", isOn: $showIncidents)
                        Toggle("NGED power pins", isOn: $showPower)
                    } label: { Label("Map layers", systemImage: "square.3.layers.3d") }
                    Spacer()
                    Button { focusOnArea() } label: { Label("Area", systemImage: "rectangle.expand.vertical") }
                    Button { location.requestPosition(); centreOnCurrentPosition() } label: { Label("Locate", systemImage: "location.fill") }
                    Button { Task { await refresh() } } label: { Image(systemName: "arrow.clockwise") }
                }.buttonStyle(.bordered)
                HStack(spacing: 8) {
                    Label("Area", systemImage: "square.dashed").foregroundStyle(Brand.amber)
                    Label("Zones", systemImage: "square.fill").foregroundStyle(Brand.teal)
                    if showWarnings { Label("Warnings", systemImage: "exclamationmark.triangle.fill").foregroundStyle(Brand.amber) }
                }.font(.caption)
                Text(sourceDescription).font(.caption).foregroundStyle(Brand.muted)
                Text("\(boundaries.filter { $0.kind == "operational_area" }.count) boundary · \(boundaries.filter { $0.kind != "operational_area" }.count) group zones · \(points.count) MIS points · \(incidents.count) incidents")
                    .font(.caption).foregroundStyle(Brand.muted)
                if showWarnings { Text(weatherStatus).font(.caption).foregroundStyle(Brand.amber) }
                if showFloods { Text(floodMapStatus).font(.caption).foregroundStyle(Brand.amber) }
                if let errorMessage { Text(errorMessage).font(.caption).foregroundStyle(Brand.amber) }
                if showPower, let powerMessage { Text(powerMessage).font(.caption).foregroundStyle(Brand.amber) }
                if auth.offline { Text("OFFLINE · stored data may be outdated; the bundled boundary is provisional").font(.caption).foregroundStyle(Brand.amber) }
            }.padding(10).background(Brand.surface)
        }
        .navigationTitle("Operational map")
        .task { await refresh() }
        .onChange(of: showWarnings) { _, enabled in
            if enabled { Task { await refreshWarnings() } }
        }
        .onChange(of: showFloods) { _, enabled in
            if enabled { Task { await refreshFloods() } }
        }
        .onChange(of: showPower) { _, enabled in
            if enabled { Task { await refresh() } }
        }
        .onChange(of: location.location?.timestamp) { _, _ in centreOnCurrentPosition() }
    }

    private func mapColour(_ hex: String, fallback: Color) -> Color {
        let raw = hex.trimmingCharacters(in: CharacterSet(charactersIn: "#"))
        guard raw.count == 6, let number = UInt64(raw, radix: 16) else { return fallback }
        return Color(red: Double((number >> 16) & 255) / 255,
                     green: Double((number >> 8) & 255) / 255,
                     blue: Double(number & 255) / 255)
    }
    private func warningColour(_ severity: String) -> Color {
        switch severity { case "RED": return .red; case "AMBER": return .orange; default: return .yellow }
    }

    private func refresh() async {
        errorMessage = nil
        // If the optional dynamic boundary route is missing, show the checked-in
        // provisional geometry rather than fabricating current zone divisions.
        do {
            let remote: GeoJSONCollectionReply = try await auth.get("operational-zones", as: GeoJSONCollectionReply.self)
            let geometry = try GeoJSONPolygons.decode(remote.rawData)
            if geometry.isEmpty { throw GeoJSONProblem.invalidCollection }
            boundaries = geometry
            sourceDescription = "MIS supplied geometry; check zone descriptions for verification status"
        } catch {
            boundaries = GeoJSONPolygons.bundled()
            sourceDescription = "Bundled provisional boundary (approximate); live zones not available"
        }
        do { points = try await auth.get("map/points", as: ItemsReply<MapPoint>.self).items }
        catch { errorMessage = "Map points: \(error.localizedDescription)" }
        do { incidents = try await auth.get("alerts/group-incidents", as: ItemsReply<GroupIncident>.self).items }
        catch { errorMessage = "Incidents: \(error.localizedDescription)" }
        if showPower {
            do {
                let nged: PowerAlertReply = try await auth.get("alerts/power", as: PowerAlertReply.self)
                powerPins = nged.feed.data?.pins ?? []
                powerMessage = nged.feed.ok ? nil : (nged.feed.error ?? "NGED feed unavailable")
            } catch { powerMessage = error.localizedDescription }
        }
        if showWarnings { await refreshWarnings() }
        if showFloods { await refreshFloods() }
    }

    private func refreshFloods() async {
        do {
            let reply: JSONValue = try await auth.get("alerts/flood", as: JSONValue.self)
            let data = try JSONEncoder().encode(reply)
            let mapped = try FloodMapOverlays.decode(data)
            floodPolygons = mapped.polygons
            floodPins = mapped.pins
            floodMapStatus = mapped.status.isEmpty ? "No provider status returned — not an all-clear." : mapped.status
        } catch {
            floodPolygons = []; floodPins = []
            floodMapStatus = "Flood layers unavailable: \(error.localizedDescription)"
        }
    }

    private func refreshWarnings() async {
        do {
            let reply: GeoJSONWeatherReply = try await auth.get("alerts/weather", as: GeoJSONWeatherReply.self)
            if !reply.ok {
                weatherZones = []
                weatherStatus = reply.error ?? "Met Office feed unavailable or unconfigured"
                return
            }
            weatherZones = try GeoJSONPolygons.decodeWeather(reply.rawData)
            weatherStatus = "\(weatherZones.count) mapped official warning polygons · \(reply.checkedAt ?? "Time unknown") · verify on Met Office"
        } catch {
            weatherZones = []
            weatherStatus = "Weather polygons unavailable: \(error.localizedDescription)"
        }
    }
    private func focusOnArea() {
        let coords = boundaries.filter { $0.kind == "operational_area" }.flatMap(\.coordinates)
        guard !coords.isEmpty else { return }
        let minLat = coords.map(\.latitude).min() ?? 51.6
        let maxLat = coords.map(\.latitude).max() ?? 51.8
        let minLon = coords.map(\.longitude).min() ?? -2.9
        let maxLon = coords.map(\.longitude).max() ?? -2.6
        camera = .region(MKCoordinateRegion(center: .init(latitude: (minLat + maxLat) / 2,
                                                            longitude: (minLon + maxLon) / 2),
            span: .init(latitudeDelta: max(0.10, (maxLat - minLat) * 1.3),
                        longitudeDelta: max(0.12, (maxLon - minLon) * 1.3))))
    }
    private func centreOnCurrentPosition() {
        guard let c = location.location?.coordinate else { return }
        camera = .region(MKCoordinateRegion(center: c,
            span: MKCoordinateSpan(latitudeDelta: 0.02, longitudeDelta: 0.03)))
    }
}

struct AlertsView: View {
    @EnvironmentObject private var auth: AuthStore
    @State private var power: PowerAlertReply?
    @State private var incidents: [GroupIncident] = []
    @State private var warningMessage = "Not yet checked"
    @State private var floodMessage = "Not yet checked"
    @State private var floodSources: FloodSources?
    @State private var errors: [String] = []
    var body: some View {
        List {
            Section("NGED power feed") {
                if let power {
                    LabeledContent("Feed", value: power.feed.ok ? "Responding" : "Unavailable")
                    LabeledContent("Checked", value: power.feed.checkedAt ?? "Unknown")
                    LabeledContent("Records", value: String(power.feed.data?.totalRecords ?? 0))
                    LabeledContent("Unlocated", value: String(power.feed.data?.missingCoordinates ?? 0))
                    if let message = power.feed.error { Text(message).foregroundStyle(Brand.amber) }
                    if let pins = power.feed.data?.pins {
                        ForEach(pins) { pin in
                            VStack(alignment: .leading) {
                                Text(pin.title).font(.headline)
                                Text(pin.status ?? "Reported").font(.caption).foregroundStyle(Brand.amber)
                            }
                        }
                    }
                } else { Text("Not loaded or unavailable").foregroundStyle(Brand.muted) }
            }
            Section("Group incidents") {
                ForEach(incidents) { incident in
                    VStack(alignment: .leading) {
                        Text(incident.title).font(.headline)
                        Text("\(incident.status) · \(incident.severity ?? "")").font(.caption).foregroundStyle(Brand.amber)
                    }
                }
                if incidents.isEmpty { Text("No returned active records — not proof of all-clear.").foregroundStyle(Brand.muted) }
            }
            Section("Official weather feed") { Text(warningMessage) }
            Section("Official flood warnings") {
                if let sources = floodSources {
                    floodProvider("Environment Agency", feed: sources.environmentAgency)
                    floodProvider("Natural Resources Wales", feed: sources.naturalResourcesWales)
                } else {
                    Text(floodMessage).foregroundStyle(Brand.muted)
                }
            }
            Section("Source reliability") {
                Text("An empty feed or missing coordinates does not mean there are no incidents. Check official sources before operational use.")
                ForEach(errors, id: \.self) { Text($0).font(.caption).foregroundStyle(Brand.amber) }
            }
        }.scrollContentBackground(.hidden).background(Brand.background).listRowBackground(Brand.surface)
            .navigationTitle("Warnings & alerts")
            .toolbar { Button { Task { await refresh() } } label: { Image(systemName: "arrow.clockwise") } }
            .task { await refresh() }
    }
    @ViewBuilder
    private func floodProvider(_ label: String, feed: FloodFeed) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(label).font(.headline).foregroundStyle(Brand.teal)
            LabeledContent("Status", value: feed.ok ? "Feed responding" : "Feed unavailable")
            LabeledContent("Checked", value: feed.checkedAt ?? "Unknown")
            if feed.configured == false { Text("Source not configured").foregroundStyle(Brand.amber) }
            if let error = feed.error, !error.isEmpty { Text(error).foregroundStyle(Brand.amber) }
            if let data = feed.data {
                Text("Located warning features: \(data.features.count)").font(.caption)
                if let total = data.totalActive { Text("Provider active records: \(total)").font(.caption) }
                if let unlocated = data.unlocated, unlocated > 0 {
                    Text("Warnings without coordinates: \(unlocated)").font(.caption).foregroundStyle(Brand.amber)
                }
                ForEach(data.features.indices, id: \.self) { index in
                    let feature = data.features[index]
                    VStack(alignment: .leading, spacing: 3) {
                        Text(feature.properties.name ?? "Unnamed warning").font(.subheadline.bold())
                        Text(feature.properties.severity ?? "Severity not provided").font(.caption).foregroundStyle(Brand.amber)
                        if let message = feature.properties.message, !message.isEmpty {
                            Text(message).font(.caption).textSelection(.enabled)
                        }
                        if let link = feature.properties.link, let url = URL(string: link), url.scheme == "https" {
                            Link("Official warning source", destination: url).font(.caption)
                        }
                    }.padding(.vertical, 4)
                }
            }
        }
    }
    private func refresh() async {
        errors = []
        do { power = try await auth.get("alerts/power", as: PowerAlertReply.self) }
        catch { errors.append("Power: \(error.localizedDescription)") }
        do { incidents = try await auth.get("alerts/group-incidents", as: ItemsReply<GroupIncident>.self).items }
        catch { errors.append("Group incidents: \(error.localizedDescription)") }
        // The portal v1 weather/flood feeds are provider-specific JSON; show their HTTP/feed status,
        // not a fabricated warning count or fictitious all-clear.
        do {
            let data: JSONValue = try await auth.get("alerts/weather", as: JSONValue.self)
            warningMessage = data.summary
        } catch { warningMessage = "Weather feed unavailable: \(error.localizedDescription)" }
        do {
            floodSources = try await auth.get("alerts/flood", as: FloodSources.self)
            floodMessage = "Provider data received; inspect the individual feeds."
        } catch { floodSources = nil; floodMessage = "Flood feed unavailable: \(error.localizedDescription)" }
    }
}

/// Provider-independent JSON decoding, without inventing a fixed external-feed schema.
enum JSONValue: Codable {
    case object([String: JSONValue]), array([JSONValue]), string(String), number(Double), bool(Bool), null
    init(from decoder: Decoder) throws {
        let box = try decoder.singleValueContainer()
        if box.decodeNil() { self = .null }
        else if let v = try? box.decode(Bool.self) { self = .bool(v) }
        else if let v = try? box.decode(String.self) { self = .string(v) }
        else if let v = try? box.decode(Double.self) { self = .number(v) }
        else if let v = try? box.decode([String: JSONValue].self) { self = .object(v) }
        else if let v = try? box.decode([JSONValue].self) { self = .array(v) }
        else { throw DecodingError.dataCorruptedError(in: box, debugDescription: "Invalid JSON") }
    }
    func encode(to encoder: Encoder) throws {
        var box = encoder.singleValueContainer()
        switch self {
        case .object(let v): try box.encode(v)
        case .array(let v): try box.encode(v)
        case .string(let v): try box.encode(v)
        case .number(let v): try box.encode(v)
        case .bool(let v): try box.encode(v)
        case .null: try box.encodeNil()
        }
    }
    var summary: String {
        switch self {
        case .object(let fields):
            if case .string(let error)? = fields["error"], !error.isEmpty { return "Provider error: \(error)" }
            let keys = fields.keys.sorted().joined(separator: ", ")
            return "Data received (fields: \(keys)). Check MIS for the detailed official feed; no all-clear inferred."
        case .array(let values): return "Received \(values.count) records; verify source before operational use."
        default: return "Feed responded; consult portal for interpretation."
        }
    }
}
