import Foundation
import CoreLocation

/// GeoJSON feature parsing. Longitudes precede latitudes, per RFC 7946.
/// Map geometry is read from the authenticated MIS endpoint. The bundled
/// source is a *provisional* reference boundary and is not authoritative.
struct OperationalPolygon: Identifiable {
    let id: String
    let name: String
    let kind: String
    let description: String
    let status: String
    let colour: String
    let coordinates: [CLLocationCoordinate2D]
}

struct GeoJSONPolygons {
    /// Unsupported or interior rings are not silently filled in. For operational
    /// polygons with holes, the first outer ring is used as an outline only.
    static func decode(_ data: Data) throws -> [OperationalPolygon] {
        guard let object = try JSONSerialization.jsonObject(with: data) as? [String: Any],
              object["type"] as? String == "FeatureCollection",
              let features = object["features"] as? [[String: Any]] else {
            throw GeoJSONProblem.invalidCollection
        }
        var result: [OperationalPolygon] = []
        for (index, feature) in features.prefix(300).enumerated() {
            guard let geom = feature["geometry"] as? [String: Any],
                  let type = geom["type"] as? String,
                  let props = feature["properties"] as? [String: Any] else { continue }
            let category = (props["kind"] as? String) ?? "zone"
            let name = (props["name"] as? String) ?? (props["warningHeadline"] as? String) ?? "Area"
            let status = (props["status"] as? String) ?? ""
            let description = (props["description"] as? String) ?? ""
            let colour = (props["color"] as? String) ?? ""
            let id = (props["id"] as? String) ?? "feature-\(index)"
            let coordinates = geom["coordinates"]
            // GeoJSON geometry has [[[lon,lat],...]] for Polygon and
            // [[[[lon,lat],...]]] for MultiPolygon. Only outer rings are drawn.
            let rings: [[[Any]]]
            if type == "Polygon", let polygon = coordinates as? [Any] {
                rings = polygon.first.flatMap(Self.outerRing).map { [$0] } ?? []
            } else if type == "MultiPolygon", let polygons = coordinates as? [Any] {
                rings = polygons.compactMap { element in
                    guard let polygon = element as? [Any], let first = polygon.first,
                          let outer = Self.outerRing(first) else { return nil }
                    return [outer]
                }
            } else { continue }
            for (part, ring) in rings.enumerated() {
                let locations = ring.compactMap { pair -> CLLocationCoordinate2D? in
                    guard pair.count >= 2, let lon = pair[0] as? NSNumber,
                          let lat = pair[1] as? NSNumber,
                          abs(lat.doubleValue) <= 90, abs(lon.doubleValue) <= 180 else { return nil }
                    return CLLocationCoordinate2D(latitude: lat.doubleValue, longitude: lon.doubleValue)
                }
                guard locations.count >= 4, locations.count == ring.count else { continue }
                result.append(OperationalPolygon(id: "\(id)-\(part)", name: name, kind: category,
                    description: description, status: status, colour: colour,
                    coordinates: locations))
            }
        }
        return result
    }

    private static func outerRing(_ raw: Any) -> [[Any]]? {
        guard let ring = raw as? [Any] else { return nil }
        return ring as? [[Any]]
    }

    static func bundled() -> [OperationalPolygon] {
        guard let url = Bundle.main.url(forResource: "operational_zones.default", withExtension: "json"),
              let data = try? Data(contentsOf: url),
              let polygons = try? decode(data) else { return [] }
        return polygons
    }

    static func decodeWeather(_ data: Data) throws -> [WeatherZone] {
        guard let outer = try JSONSerialization.jsonObject(with: data) as? [String: Any],
              let feed = outer["data"] as? [String: Any],
              let features = feed["features"] as? [[String: Any]] else { return [] }
        var zones: [WeatherZone] = []
        for (i, feat) in features.prefix(100).enumerated() {
            guard let props = feat["properties"] as? [String: Any],
                  let geom = feat["geometry"] as? [String: Any],
                  let level = props["warningLevel"] as? String,
                  ["YELLOW", "AMBER", "RED"].contains(level.uppercased()) else { continue }
            let title = (props["warningHeadline"] as? String) ?? "Met Office warning"
            let geometryFeature: [String: Any] = ["type":"Feature", "geometry":geom,
                "properties":["id":"warning-\(i)","name":title,"kind":"weather_warning"]]
            let collection: [String: Any] = ["type":"FeatureCollection","features":[geometryFeature]]
            let payload = try JSONSerialization.data(withJSONObject: collection)
            for p in try decode(payload) {
                zones.append(WeatherZone(id: p.id, title: title, severity: level.uppercased(), coordinates: p.coordinates))
            }
        }
        return zones
    }
}

enum GeoJSONProblem: LocalizedError {
    case invalidCollection
    var errorDescription: String? { "No valid operational GeoJSON FeatureCollection was returned." }
}

struct WeatherZone: Identifiable {
    let id: String
    let title: String
    let severity: String
    let coordinates: [CLLocationCoordinate2D]
}

// Round-trip the GeoJSON payload through the project's JSONValue model.
// No other changes to AuthStore's bearer/opt-in cache behaviour are required.
struct GeoJSONCollectionReply: Decodable {
    let fields: JSONValue
    init(from decoder: Decoder) throws { fields = try JSONValue(from: decoder) }
    var rawData: Data { (try? JSONEncoder().encode(fields)) ?? Data() }
}
struct GeoJSONWeatherReply: Decodable {
    let fields: JSONValue
    init(from decoder: Decoder) throws { fields = try JSONValue(from: decoder) }
    var rawData: Data { (try? JSONEncoder().encode(fields)) ?? Data() }
    var ok: Bool {
        if case .object(let obj) = fields, case .bool(let success)? = obj["ok"] { return success }
        return false
    }
    var error: String? {
        if case .object(let obj) = fields, case .string(let text)? = obj["error"] { return text }
        return nil
    }
    var checkedAt: String? {
        if case .object(let obj) = fields, case .string(let text)? = obj["checked_at"] { return text }
        return nil
    }
}

/// Only official, explicitly geolocated flood features are mapped. Records
/// without provider coordinates remain in the textual flood warning list.
struct FloodMapPin: Identifiable {
    let id: String
    let title: String
    let severity: String
    let coordinate: CLLocationCoordinate2D
}
struct FloodMapOverlays {
    let polygons: [OperationalPolygon]
    let pins: [FloodMapPin]
    let status: String

    static func decode(_ data: Data) throws -> FloodMapOverlays {
        guard let root = try JSONSerialization.jsonObject(with: data) as? [String: Any] else {
            throw GeoJSONProblem.invalidCollection
        }
        var polygons: [OperationalPolygon] = []
        var pins: [FloodMapPin] = []
        var statuses: [String] = []
        for (provider, label) in [("environment_agency", "EA"), ("natural_resources_wales", "NRW")] {
            guard let feed = root[provider] as? [String: Any] else { continue }
            guard feed["ok"] as? Bool == true,
                  let collection = feed["data"] as? [String: Any],
                  let features = collection["features"] as? [[String: Any]] else {
                let issue = feed["error"] as? String ?? "feed unavailable"
                statuses.append("\(label): \(issue)")
                continue
            }
            statuses.append("\(label): \(features.count) located features")
            for (i, feature) in features.prefix(600).enumerated() {
                guard let geom = feature["geometry"] as? [String: Any],
                      let type = geom["type"] as? String else { continue }
                let props = feature["properties"] as? [String: Any] ?? [:]
                let title = props["name"] as? String ?? "\(label) flood warning"
                let severity = props["severity"] as? String ?? "Severity not supplied"
                let identifier = "\(label)-\(i)"
                if type == "Point", let coord = geom["coordinates"] as? [NSNumber], coord.count >= 2 {
                    let lon = coord[0].doubleValue, lat = coord[1].doubleValue
                    if (49...61).contains(lat), (-9...3).contains(lon) {
                        pins.append(FloodMapPin(id: identifier, title: title, severity: severity,
                            coordinate: CLLocationCoordinate2D(latitude: lat, longitude: lon)))
                    }
                } else if type == "Polygon" || type == "MultiPolygon" {
                    // Use the same polygon decoder as the operational boundaries.
                    let publicProps: [String: Any] = ["id": identifier, "name": title,
                        "kind": "flood_warning", "status": severity,
                        "color": props["colour"] as? String ?? "#f6b651"]
                    let fc: [String: Any] = ["type": "FeatureCollection", "features": [
                        ["type": "Feature", "geometry": geom, "properties": publicProps]]]
                    if let bytes = try? JSONSerialization.data(withJSONObject: fc),
                       let shapes = try? GeoJSONPolygons.decode(bytes) {
                        polygons += shapes
                    }
                }
            }
        }
        return FloodMapOverlays(polygons: polygons, pins: pins,
                                status: statuses.joined(separator: " · "))
    }
}
