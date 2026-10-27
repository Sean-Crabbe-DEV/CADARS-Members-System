import Foundation

func decode<T: Decodable>(_ payload: String, as: T.Type) throws -> T {
    try JSONDecoder.raynet().decode(T.self, from: Data(payload.utf8))
}
let inbox = try decode(#"{"server_utc":"2026-10-27T21:00:00Z","unanswered_count":1,"activations":[{"id":12,"title":"Standby","message":"Radio test","kind":"Standby","priority":"Priority","status":"Open","event_id":null,"created_at":"2026-10-27T20:00:00Z","response":"Pending","responded_at":null}]}"#, as: ActivationInbox.self)
precondition(inbox.unansweredCount == 1 && inbox.activations[0].unanswered)
let created = try decode(#"{"id":12,"title":"Standby","message":"Radio test","kind":"Standby","priority":"Priority","status":"Open","event_id":null,"created_at":"2026-10-27T20:00:00Z","response":null,"responded_at":null}"#, as: Activation.self)
precondition(created.status == "Open")
let exList = try decode(#"{"exercises":[{"event_id":17,"title":"Coverage","day":"2026-10-28","location":"Chepstow","brief_status":"Published"}]}"#, as: ExerciseList.self)
precondition(exList.exercises[0].id == 17)
let exercise = try decode(#"{"event":{"id":17,"title":"Coverage","day":"2026-10-28","start_time":"19:00","location":"Chepstow"},"brief":"Briefing","status":"Published","updates":[{"id":1,"body":"Nets open","created_at":"2026-10-27T19:00:00Z"}],"messages":[{"id":2,"event_id":17,"message_number":"SED0001","precedence":"Routine","dtg":"271945OCT","message_from":"Control","message_to":"Station A","information":"","message_body":"Test message","originator":"Control","released_by":"Controller","received_by":"Operator","received_dtg":"271950OCT","received_method":"Voice","transmitted_by":"","transmitted_dtg":"","transmitted_method":"","created_at":"2026-10-27T19:50:00Z","has_pdf":false}]}"#, as: ExerciseDetail.self)
precondition(exercise.messages[0].messageNumber == "SED0001")
let attendance = try decode(#"{"event_id":17,"event_title":"Coverage","day":"2026-10-28","summary":{"Present":1,"Remote":0,"Absent":0,"Apologies":0},"members":[{"id":2,"name":"Member","callsign":"M7ABC","rsvp":"Available","status":"Present","notes":""}]}"#, as: AttendanceDetail.self)
precondition(attendance.members[0].status == "Present")
let floods = try decode(#"{"environment_agency":{"ok":true,"checked_at":"2026-10-27T21:00:00Z","error":null,"data":{"type":"FeatureCollection","features":[{"type":"Feature","geometry":{"type":"Point","coordinates":[-2.7,51.6]},"properties":{"name":"Sample flood warning","severity":"Flood Alert","source":"Environment Agency"}}],"total_active":1,"unlocated":0}},"natural_resources_wales":{"ok":false,"configured":false,"data":null,"checked_at":"2026-10-27T21:00:00Z","error":"Feed not configured"}}"#, as: FloodSources.self)
precondition(floods.environmentAgency.data?.features.count == 1)
print("Operations API model smoke tests passed: 6 payloads")
