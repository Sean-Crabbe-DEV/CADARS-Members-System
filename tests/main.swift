import Foundation
// Reference points validated with pyproj EPSG:4326 -> EPSG:27700, within 10m.
let tests: [(Double, Double, Int, Int, String)] = [
    (51.642, -2.676, 353319, 193905, "ST"),
    (51.5074, -0.1278, 530028, 180380, "TQ"),
    (55.9533, -3.1883, 325897, 674001, "NT")
]
for (lat, lon, east, north, prefix) in tests {
    guard let result = BritishNationalGrid.convert(latitude: lat, longitude: lon) else {
        fatalError("Conversion returned nil for \(lat),\(lon)")
    }
    print("\(lat),\(lon) -> \(result.gridReference), E=\(result.easting), N=\(result.northing)")
    precondition(result.gridReference.hasPrefix(prefix), "Incorrect grid letters")
    precondition(abs(result.easting - east) < 150 && abs(result.northing - north) < 150,
                 "Unexpected grid coordinate")
}
precondition(BritishNationalGrid.convert(latitude: 40, longitude: -2) == nil)
print("Grid conversion smoke tests passed")
