import Foundation
import CoreLocation
import Combine

@MainActor
final class LocationLookupService: ObservableObject {
    @Published private(set) var nearestPostcode: String?
    @Published private(set) var postcodeDistance: Double?
    @Published private(set) var threeWordAddress: String?
    @Published private(set) var status: String?
    @Published private(set) var loading = false
    private var coordinate: CLLocationCoordinate2D?

    func reset(for location: CLLocation) {
        let c = location.coordinate
        if let previous = coordinate,
           abs(previous.latitude - c.latitude) < 0.00001,
           abs(previous.longitude - c.longitude) < 0.00001 { return }
        coordinate = c
        nearestPostcode = nil; postcodeDistance = nil; threeWordAddress = nil
        status = "Lookups only send coordinates when you select Lookup."
    }
    func clear() {
        coordinate = nil; nearestPostcode = nil; postcodeDistance = nil
        threeWordAddress = nil; status = nil; loading = false
    }
    func lookup(coordinate c: CLLocationCoordinate2D) async {
        guard !loading else { return }
        loading = true
        defer { loading = false }
        var messages: [String] = []
        do {
            var parts = URLComponents(string: "https://api.postcodes.io/postcodes")!
            parts.queryItems = [.init(name: "lat", value: String(c.latitude)),
                                .init(name: "lon", value: String(c.longitude)),
                                .init(name: "limit", value: "1")]
            let (data, response) = try await URLSession.shared.data(from: parts.url!)
            guard (response as? HTTPURLResponse)?.statusCode == 200,
                  let nearest = try JSONDecoder().decode(PostcodeResponse.self, from: data).result?.first else {
                throw LookupError.noResult
            }
            nearestPostcode = nearest.postcode; postcodeDistance = nearest.distance
        } catch { messages.append("Postcode: \(error.localizedDescription)") }
        if let key = KeychainStore.load(account: "what3words_api_key"), !key.isEmpty {
            do {
                var parts = URLComponents(string: "https://api.what3words.com/v3/convert-to-3wa")!
                parts.queryItems = [.init(name: "coordinates", value: "\(c.latitude),\(c.longitude)"),
                                    .init(name: "language", value: "en")]
                var request = URLRequest(url: parts.url!)
                request.setValue(key, forHTTPHeaderField: "X-Api-Key")
                let (data, response) = try await URLSession.shared.data(for: request)
                guard (response as? HTTPURLResponse)?.statusCode == 200,
                      let words = try? JSONDecoder().decode(ThreeWordsResponse.self, from: data).words,
                      !words.isEmpty else { throw LookupError.noResult }
                threeWordAddress = "///" + words
            } catch { messages.append("what3words: \(error.localizedDescription)") }
        } else { messages.append("what3words in-app lookup needs a key. Use Open in what3words instead.") }
        status = messages.isEmpty ? "Lookups completed." : messages.joined(separator: " ")
    }
}
private struct PostcodeResponse: Decodable { let result: [PostcodeResult]? }
private struct PostcodeResult: Decodable { let postcode: String; let distance: Double? }
private struct ThreeWordsResponse: Decodable { let words: String }
private enum LookupError: LocalizedError {
    case noResult
    var errorDescription: String? { "Lookup service returned no usable result." }
}
