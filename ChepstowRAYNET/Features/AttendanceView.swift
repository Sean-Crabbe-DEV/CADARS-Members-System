import SwiftUI

// Requires the separately supplied optional mobile attendance server patch.
// Stock portal v0.3.4.1 has browser-only attendance pages.
struct AttendanceView: View {
    @EnvironmentObject private var auth: AuthStore
    @State private var month = Date()
    @State private var events: [AttendanceEvent] = []
    @State private var errorMessage: String?
    private var monthString: String {
        let df = DateFormatter()
        df.dateFormat = "yyyy-MM"
        df.timeZone = TimeZone(identifier: "Europe/London")
        return df.string(from: month)
    }
    var body: some View {
        List {
            Section("Event attendance") {
                DatePicker("Month", selection: $month, displayedComponents: .date)
                ForEach(events) { item in
                    NavigationLink { AttendanceEventView(eventID: item.id) } label: {
                        VStack(alignment: .leading, spacing: 4) {
                            Text(item.title).font(.headline)
                            Text(item.day).font(.caption).foregroundStyle(Brand.muted)
                            Text("Present \(item.counts["Present", default: 0]) · Remote \(item.counts["Remote", default: 0]) · Apologies \(item.counts["Apologies", default: 0])")
                                .font(.caption).foregroundStyle(Brand.teal)
                        }
                    }
                }
            }
            if let errorMessage { Text(errorMessage).foregroundStyle(Brand.amber) }
            Text("Native attendance requires the optional server API patch. Portal v0.3.4.1 only provides this feature through authenticated web pages.")
                .font(.caption).foregroundStyle(Brand.muted)
        }
        .scrollContentBackground(.hidden).listRowBackground(Brand.surface).background(Brand.background)
        .navigationTitle("Attendance")
        .task(id: monthString) { await refresh() }
        .refreshable { await refresh() }
    }
    private func refresh() async {
        do {
            let response: AttendanceMonth = try await auth.get("attendance/events?month=\(monthString)", as: AttendanceMonth.self)
            events = response.events; errorMessage = nil
        } catch { errorMessage = error.localizedDescription }
    }
}

struct AttendanceEventView: View {
    @EnvironmentObject private var auth: AuthStore
    let eventID: Int
    @State private var data: AttendanceDetail?
    @State private var errorMessage: String?
    var body: some View {
        List {
            if let data {
                Section("\(data.eventTitle) · \(data.day)") {
                    Text("Present \(data.summary["Present", default: 0]) · Remote \(data.summary["Remote", default: 0]) · Absent \(data.summary["Absent", default: 0]) · Apologies \(data.summary["Apologies", default: 0])")
                }
                Section("Member attendance") {
                    ForEach(data.members) { person in
                        NavigationLink { AttendanceMemberEditor(eventID: eventID, person: person, onSaved: { Task { await refresh() } }) } label: {
                            VStack(alignment: .leading, spacing: 3) {
                                Text("\(person.name) \(person.callsign ?? "")").font(.headline)
                                Text("Recorded: \(person.status ?? "Not recorded") · RSVP: \(person.rsvp ?? "None")")
                                    .font(.caption).foregroundStyle(Brand.muted)
                            }
                        }
                    }
                }
            } else { ProgressView("Loading attendance…") }
            if let errorMessage { Text(errorMessage).foregroundStyle(Brand.amber) }
        }.scrollContentBackground(.hidden).listRowBackground(Brand.surface).background(Brand.background)
            .navigationTitle("Event attendance").task { await refresh() }.refreshable { await refresh() }
    }
    private func refresh() async {
        do { data = try await auth.get("attendance/events/\(eventID)", as: AttendanceDetail.self); errorMessage = nil }
        catch { errorMessage = error.localizedDescription }
    }
}

struct AttendanceMemberEditor: View {
    @EnvironmentObject private var auth: AuthStore
    @Environment(\.dismiss) private var dismiss
    let eventID: Int
    let person: AttendanceMember
    let onSaved: () -> Void
    @State private var status: String
    @State private var notes: String
    @State private var errorMessage: String?
    init(eventID: Int, person: AttendanceMember, onSaved: @escaping () -> Void) {
        self.eventID = eventID
        self.person = person
        self.onSaved = onSaved
        _status = State(initialValue: person.status ?? "Not recorded")
        _notes = State(initialValue: person.notes)
    }
    var body: some View {
        Form {
            Section(person.name) {
                LabeledContent("Event RSVP", value: person.rsvp ?? "None")
                Picker("Actual attendance", selection: $status) {
                    ForEach(["Not recorded", "Present", "Remote", "Absent", "Apologies"], id: \.self) { Text($0) }
                }
                TextField("Notes (up to 250 characters)", text: $notes, axis: .vertical).lineLimit(2...5)
            }
            Button("Save attendance") { Task { await save() } }.disabled(auth.offline || notes.count > 250)
            if let errorMessage { Text(errorMessage).foregroundStyle(Brand.amber) }
        }.navigationTitle("Attendance record")
    }
    private func save() async {
        do {
            let _: AttendanceSaved = try await auth.write("attendance/events/\(eventID)/members/\(person.id)", method: "PUT",
                body: AttendanceSave(status: status == "Not recorded" ? nil : status, notes: notes), as: AttendanceSaved.self)
            onSaved()
            dismiss()
        } catch { errorMessage = error.localizedDescription }
    }
}
