import Foundation
@main
struct ModelSmoke {
    static func main() throws {
        let tests: [(String, Decodable.Type)] = [
            (#"{"access_token":"abc","token_type":"Bearer","expires_at":"2026-11-24T15:30:00Z","user":{"id":12,"name":"Example Member","callsign":"M7XXX","role":"member","username":"example","phone":"","active":true}}"#, LoginReply.self),
            (#"{"user":{"id":12,"name":"Example Member","callsign":null,"role":"member"},"upcoming_events":[{"id":1,"title":"Test","day":"2026-11-01","start_time":"TBC","end_time":"TBC","location":"","kind":"Training","notes":"","my_rsvp":null}],"training_required":4,"training_verified":3,"verified_experience_hours":1.5}"#, DashboardReply.self),
            (#"{"items":[{"id":2,"user_id":12,"start_day":"2026-11-01","end_day":"2026-11-03","status":"Available","notes":""}]}"#, ItemsReply<AvailabilityEntry>.self),
            (#"{"items":[{"id":4,"owner_id":12,"category":"Handheld radio","make_model":"GD88","band":"2m; 70cm","modes":"DMR","power":"5W","deployable":true,"notes":""}]}"#, ItemsReply<EquipmentEntry>.self),
            (#"{"items":[{"id":4,"title":"Control","point_type":"Control","lat":51.64,"lon":-2.67,"notes":""}]}"#, ItemsReply<MapPoint>.self),
            (#"{"provider":"NGED","feed":{"ok":false,"checked_at":"2026-10-25T15:00:00Z","error":"Official feed unavailable","data":null}}"#, PowerAlertReply.self)
        ]
        for (payload, type) in tests {
            // Validates the actual backend's snake_case fields and optional values.
            let input = Data(payload.utf8)
            _ = try JSONDecoder.raynet().decode(type, from: input)
        }
        print("Mobile API response model smoke tests passed: \(tests.count) payloads")
    }
}
