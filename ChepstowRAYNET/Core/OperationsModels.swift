import Foundation

// Confirmed against app/operations_activation.py, portal v0.3.4.1.
struct ActivationInbox: Codable {
    let serverUtc: String
    let unansweredCount: Int
    let activations: [Activation]
}
struct ActivationList: Codable { let activations: [Activation] }
struct Activation: Codable, Identifiable {
    let id: Int
    let title: String
    let message: String
    let kind: String
    let priority: String
    let status: String
    let eventId: Int?
    let createdAt: String
    let response: String?
    let respondedAt: String?
    var unanswered: Bool { status == "Open" && (response == "Pending" || response == nil) }
}
struct ActivationReply: Codable {
    let activationId: Int
    let response: String
    let respondedAt: String
}
struct ActivationResponseInput: Encodable { let response: String }
struct ActivationCreateInput: Encodable {
    let title: String
    let message: String
    let kind: String
    let priority: String
    let eventId: Int?
    let recipientIds: [Int]
}
struct ActivationRecipient: Codable, Identifiable {
    let userId: Int
    let name: String
    let callsign: String?
    let response: String
    let respondedAt: String?
    var id: Int { userId }
}
struct ActivationRoster: Codable {
    let activation: Activation
    let recipients: [ActivationRecipient]
}
struct ActivationCloseReply: Codable { let id: Int; let status: String }

struct ExerciseList: Codable { let exercises: [ExerciseSummary] }
struct ExerciseSummary: Codable, Identifiable {
    let eventId: Int
    let title: String
    let day: String
    let location: String?
    let briefStatus: String
    var id: Int { eventId }
}
struct ExerciseEvent: Codable {
    let id: Int
    let title: String
    let day: String
    let startTime: String?
    let location: String?
}
struct ExerciseDetail: Codable {
    let event: ExerciseEvent
    let brief: String
    let status: String
    let updates: [ExerciseUpdate]
    let messages: [ExerciseMessage]
}
struct ExerciseUpdate: Codable, Identifiable {
    let id: Int
    let body: String
    let createdAt: String?
}
struct ExerciseMessage: Codable, Identifiable {
    let id: Int
    let eventId: Int
    let messageNumber: String
    let precedence: String
    let dtg: String
    let messageFrom: String
    let messageTo: String
    let information: String
    let messageBody: String
    let originator: String
    let releasedBy: String
    let receivedBy: String
    let receivedDtg: String
    let receivedMethod: String
    let transmittedBy: String
    let transmittedDtg: String
    let transmittedMethod: String
    let hasPdf: Bool
    let createdAt: String
}
struct ExerciseBriefInput: Encodable { let brief: String; let status: String }
struct ExerciseBriefReply: Decodable { let eventId: Int; let status: String; let updatedAt: String }
struct ExerciseUpdateInput: Encodable { let body: String }
struct ExerciseUpdateReply: Decodable { let id: Int; let eventId: Int; let body: String }
struct ExerciseMessageInput: Encodable {
    let messageNumber: String
    let precedence: String
    let dtg: String
    let messageFrom: String
    let messageTo: String
    let information: String
    let messageBody: String
    let originator: String
    let releasedBy: String
    let receivedBy: String
    let receivedDtg: String
    let receivedMethod: String
    let transmittedBy: String
    let transmittedDtg: String
    let transmittedMethod: String
}

// The attendance endpoints below are NOT part of the stock v0.3.4.1 API.
// They require the separately packaged optional mobile attendance extension.
struct AttendanceMonth: Codable { let events: [AttendanceEvent] }
struct AttendanceEvent: Codable, Identifiable {
    let id: Int
    let title: String
    let day: String
    let counts: [String: Int]
}
struct AttendanceDetail: Codable {
    let eventId: Int
    let eventTitle: String
    let day: String
    let members: [AttendanceMember]
    let summary: [String: Int]
}
struct AttendanceMember: Codable, Identifiable {
    let id: Int
    let name: String
    let callsign: String?
    let rsvp: String?
    let status: String?
    let notes: String
}
struct AttendanceSave: Encodable { let status: String?; let notes: String }
struct AttendanceSaved: Decodable { let eventId: Int; let userId: Int; let status: String? }

// NRW and EA provider wrappers returned by mobile_api.py /alerts/flood.
struct FloodSources: Decodable {
    let environmentAgency: FloodFeed
    let naturalResourcesWales: FloodFeed
}
struct FloodFeed: Decodable {
    let ok: Bool
    let configured: Bool?
    let checkedAt: String?
    let error: String?
    let data: FloodFeatureCollection?
}
struct FloodFeatureCollection: Decodable {
    let type: String
    let features: [FloodFeature]
    let totalActive: Int?
    let unlocated: Int?
}
struct FloodFeature: Decodable {
    let properties: FloodProperties
}
struct FloodProperties: Decodable {
    let id: String?
    let name: String?
    let severity: String?
    let message: String?
    let updated: String?
    let source: String?
    let link: String?
}
