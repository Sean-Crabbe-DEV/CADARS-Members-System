import Foundation

/// Approximate conversion from WGS84 GPS latitude/longitude to OSGB36 British National Grid.
/// Uses OS's standard 7-parameter Helmert transform, NOT the OSTN15 correction grid.
/// Datum transform accuracy is approximately 3.5 m (95%), excluding GPS uncertainty.
struct BritishNationalGrid {
    struct Result {
        let easting: Int
        let northing: Int
        let gridReference: String
    }

    static func convert(latitude: Double, longitude: Double) -> Result? {
        guard latitude.isFinite, longitude.isFinite,
              (49.0...61.5).contains(latitude), (-9.0...3.0).contains(longitude) else { return nil }
        let r = Double.pi / 180
        let phi = latitude * r
        let lambda = longitude * r
        let a1 = 6378137.0
        let b1 = 6356752.3141
        let e21 = 1 - (b1 * b1) / (a1 * a1)
        let sinP = sin(phi)
        let nu1 = a1 / sqrt(1 - e21 * sinP * sinP)
        let h = 0.0
        let x1 = (nu1 + h) * cos(phi) * cos(lambda)
        let y1 = (nu1 + h) * cos(phi) * sin(lambda)
        let z1 = ((1 - e21) * nu1 + h) * sinP

        // Ordnance Survey approximate WGS84 -> OSGB36 Helmert parameters.
        let s = 1 + 20.4894e-6
        let secondsToRadians = Double.pi / (180 * 3600)
        let rx = -0.1502 * secondsToRadians
        let ry = -0.2470 * secondsToRadians
        let rz = -0.8421 * secondsToRadians
        let x2 = -446.448 + s * x1 - rz * y1 + ry * z1
        let y2 =  125.157 + rz * x1 + s * y1 - rx * z1
        let z2 = -542.060 - ry * x1 + rx * y1 + s * z1

        let a = 6377563.396
        let b = 6356256.909
        let e2 = 1 - (b * b) / (a * a)
        let p = hypot(x2, y2)
        var phi2 = atan2(z2, p * (1 - e2))
        for _ in 0..<12 {
            let n = a / sqrt(1 - e2 * pow(sin(phi2), 2))
            let h2 = p / cos(phi2) - n
            let next = atan2(z2, p * (1 - e2 * n / (n + h2)))
            if abs(next - phi2) < 1e-12 { phi2 = next; break }
            phi2 = next
        }
        let lambda2 = atan2(y2, x2)
        let f0 = 0.9996012717
        let lat0 = 49.0 * r
        let lon0 = -2.0 * r
        let e0 = 400000.0
        let n0 = -100000.0
        let n = (a - b) / (a + b)
        let n2 = n * n
        let n3 = n2 * n
        let deltaPhi = phi2 - lat0
        let sigmaPhi = phi2 + lat0
        let m = b * f0 * (
            (1 + n + 1.25 * n2 + 1.25 * n3) * deltaPhi
            - (3 * n + 3 * n2 + 21.0 / 8 * n3) * sin(deltaPhi) * cos(sigmaPhi)
            + (15.0 / 8 * n2 + 15.0 / 8 * n3) * sin(2 * deltaPhi) * cos(2 * sigmaPhi)
            - (35.0 / 24 * n3) * sin(3 * deltaPhi) * cos(3 * sigmaPhi)
        )
        let sin2 = pow(sin(phi2), 2)
        let nu = a * f0 / sqrt(1 - e2 * sin2)
        let rho = a * f0 * (1 - e2) / pow(1 - e2 * sin2, 1.5)
        let eta2 = nu / rho - 1
        let tan2 = pow(tan(phi2), 2)
        let c = cos(phi2)
        let d = lambda2 - lon0
        let I = m + n0
        let II = nu / 2 * sin(phi2) * c
        let III = nu / 24 * sin(phi2) * pow(c, 3) * (5 - tan2 + 9 * eta2)
        let IIIA = nu / 720 * sin(phi2) * pow(c, 5) * (61 - 58 * tan2 + tan2 * tan2)
        let IV = nu * c
        let V = nu / 6 * pow(c, 3) * (nu / rho - tan2)
        let VI = nu / 120 * pow(c, 5) * (5 - 18 * tan2 + tan2 * tan2 + 14 * eta2 - 58 * tan2 * eta2)
        let north = I + II * d * d + III * pow(d, 4) + IIIA * pow(d, 6)
        let east = e0 + IV * d + V * pow(d, 3) + VI * pow(d, 5)
        guard east.isFinite, north.isFinite, east >= 0, north >= 0,
              east < 700000, north < 1300000 else { return nil }
        let ei = Int(east.rounded())
        let ni = Int(north.rounded())
        guard let letters = lettersFor(easting: ei, northing: ni) else { return nil }
        // Eight-figure reference (10m resolution); this does not promise 10m GPS accuracy.
        let eFour = (ei % 100000) / 10
        let nFour = (ni % 100000) / 10
        return Result(easting: ei, northing: ni,
                      gridReference: String(format: "%@ %04d %04d", letters, eFour, nFour))
    }

    private static func lettersFor(easting e: Int, northing n: Int) -> String? {
        let e100 = e / 100000
        let n100 = n / 100000
        let first = (19 - n100) - (19 - n100) % 5 + (e100 + 10) / 5
        let second = ((19 - n100) * 5) % 25 + e100 % 5
        func letter(_ index: Int) -> String? {
            guard index >= 0 && index < 25 else { return nil }
            let ascii = index + (index > 7 ? 66 : 65) // Skip I.
            guard let scalar = UnicodeScalar(ascii) else { return nil }
            return String(scalar)
        }
        guard let l1 = letter(first), let l2 = letter(second) else { return nil }
        return l1 + l2
    }
}
