import Foundation

struct Member: Codable, Identifiable {
    let id: Int
    let name: String
    let callsign: String?
    let role: String
    let username: String?
    let phone: String?
    let active: Bool?
    let personalEmail: String?
    let raynetEmail: String?
    var isController: Bool { role == "admin" || role == "controller" }
}
struct LoginReply: Codable {
    let accessToken: String
    let tokenType: String
    let expiresAt: String
    let user: Member
}
struct ItemsReply<T: Codable>: Codable { let items: [T] }
struct PortalEvent: Codable, Identifiable {
    let id: Int
    let title: String
    let day: String
    let startTime: String?
    let endTime: String?
    let location: String?
    let kind: String?
    let notes: String?
    let myRsvp: String?
}
struct DashboardReply: Codable {
    let user: Member
    let upcomingEvents: [PortalEvent]
    let trainingRequired: Int
    let trainingVerified: Int
    let verifiedExperienceHours: Double
}
struct AvailabilityEntry: Codable, Identifiable {
    let id: Int
    let userId: Int
    let startDay: String
    let endDay: String
    let status: String
    let notes: String?
}
struct AvailabilityInput: Encodable {
    let startDay: String
    let endDay: String
    let status: String
    let notes: String
}
struct EquipmentEntry: Codable, Identifiable {
    let id: Int
    let ownerId: Int
    let category: String
    let makeModel: String
    let band: String?
    let modes: String?
    let power: String?
    let deployable: Bool
    let notes: String?
}
struct EquipmentInput: Encodable {
    let category: String
    let makeModel: String
    let band: String
    let modes: String
    let power: String
    let deployable: Bool
    let notes: String
}
struct MapPoint: Codable, Identifiable {
    let id: Int
    let title: String
    let pointType: String?
    let lat: Double
    let lon: Double
    let notes: String?
}
struct TrainingModule: Codable, Identifiable {
    let id: Int
    let title: String
    let category: String?
    let description: String?
    let required: Bool
    let record: TrainingRecord?
}
struct TrainingRecord: Codable {
    let id: Int
    let status: String
    let evidence: String?
    let expiresOn: String?
}
struct ExperienceEntry: Codable, Identifiable {
    let id: Int
    let day: String
    let title: String
    let dutyRole: String
    let hours: Double
    let notes: String?
    let verified: Bool
}
struct ExperienceReply: Codable { let items: [ExperienceEntry]; let verifiedHours: Double }
struct ActionEntry: Codable, Identifiable {
    let id: Int
    let title: String
    let description: String?
    let category: String?
    let priority: String?
    let status: String?
    let dueDate: String?
}
struct ManualEntry: Codable, Identifiable {
    let id: Int
    let title: String
    let description: String?
    let uploadedAt: String?
    let downloadPath: String?
}
struct TeamMember: Codable, Identifiable {
    let id: Int
    let name: String
    let callsign: String?
    let role: String
}
struct EmptyReply: Codable { let ok: Bool? }
struct RSVPReply: Codable { let eventId: Int; let myRsvp: String }

extension JSONDecoder {
    static func raynet() -> JSONDecoder {
        let d = JSONDecoder()
        d.keyDecodingStrategy = .convertFromSnakeCase
        return d
    }
}
extension JSONEncoder {
    static func raynet() -> JSONEncoder {
        let e = JSONEncoder()
        e.keyEncodingStrategy = .convertToSnakeCase
        return e
    }
}
extension Date {
    var apiDay: String {
        let format = DateFormatter()
        format.locale = Locale(identifier: "en_GB_POSIX")
        format.timeZone = TimeZone(identifier: "Europe/London")
        format.dateFormat = "yyyy-MM-dd"
        return format.string(from: self)
    }
}

struct PowerAlertReply: Codable {
    let provider: String
    let feed: PowerAlertFeed
}
struct PowerAlertFeed: Codable {
    let ok: Bool
    let checkedAt: String?
    let error: String?
    let data: PowerAlertData?
}
struct PowerAlertData: Codable {
    let pins: [PowerPin]
    let totalRecords: Int?
    let missingCoordinates: Int?
}
struct PowerPin: Codable, Identifiable {
    let id: String
    let title: String
    let status: String?
    let latitude: Double
    let longitude: Double
    let description: String?
}
struct GroupIncident: Codable, Identifiable {
    let id: Int
    let title: String
    let status: String
    let severity: String?
    let latitude: Double
    let longitude: Double
    let description: String?
}
struct TeamAvailability: Codable, Identifiable {
    let id: Int
    let userId: Int
    let startDay: String
    let endDay: String
    let status: String
    let notes: String?
    let member: Member
}
struct PendingTraining: Codable, Identifiable {
    let id: Int
    let userId: Int
    let moduleId: Int
    let evidence: String?
    let submittedAt: String?
}
struct TrainingReviewInput: Encodable { let decision: String }
struct TrainingReviewReply: Decodable { let id: Int; let status: String }
struct ExperienceReviewReply: Decodable { let id: Int; let verified: Bool }
