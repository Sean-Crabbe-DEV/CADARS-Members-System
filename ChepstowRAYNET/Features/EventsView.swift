import SwiftUI

private struct RSVPInput: Encodable { let response: String }
private struct EventCreateInput: Encodable {
    let title: String
    let day: String
    let startTime: String
    let endTime: String
    let location: String
    let kind: String
    let notes: String
}

struct EventsView: View {
    @EnvironmentObject private var auth: AuthStore
    @State private var events: [PortalEvent] = []
    @State private var error: String?
    @State private var busyID: Int?
    @State private var newTitle = ""
    @State private var newDay = Date()
    @State private var newLocation = ""
    @State private var newNotes = ""
    var body: some View {
        List {
            if auth.offline { Text("OFFLINE · cached events; RSVP disabled").foregroundStyle(Brand.amber) }
            ForEach(events) { event in
                VStack(alignment: .leading, spacing: 7) {
                    Text(event.title).font(.headline)
                    Label("\(event.day) · \(event.startTime ?? "TBC")–\(event.endTime ?? "TBC")", systemImage: "calendar")
                    if let location = event.location, !location.isEmpty { Label(location, systemImage: "mappin") }
                    if let notes = event.notes, !notes.isEmpty { Text(notes).font(.caption).foregroundStyle(Brand.muted) }
                    Text("My RSVP: \(event.myRsvp ?? "Not set")").foregroundStyle(Brand.teal)
                    HStack {
                        ForEach(["Available", "Maybe", "Unavailable"], id: \.self) { response in
                            Button(response) { Task { await rsvp(event.id, response: response) } }
                                .buttonStyle(.bordered)
                                .disabled(auth.offline || busyID != nil)
                        }
                    }.font(.caption2)
                }.padding(.vertical, 6).listRowBackground(Brand.surface)
            }
            if events.isEmpty { Text(error ?? "No events returned.").foregroundStyle(Brand.muted) }
            if auth.user?.isController == true {
                Section("Controller · create event") {
                    TextField("Event title", text: $newTitle)
                    DatePicker("Date", selection: $newDay, displayedComponents: .date)
                    TextField("Location", text: $newLocation)
                    TextField("Notes", text: $newNotes)
                    Button("Create event") { Task { await createEvent() } }
                        .disabled(auth.offline || newTitle.isEmpty)
                }
            }
            if let error, !events.isEmpty { Text(error).foregroundStyle(Brand.amber) }
        }
        .scrollContentBackground(.hidden).background(Brand.background)
        .navigationTitle("Events & RSVPs")
        .refreshable { await refresh() }
        .task { await refresh() }
    }
    private func refresh() async {
        do {
            let response: ItemsReply<PortalEvent> = try await auth.get("events", as: ItemsReply<PortalEvent>.self)
            events = response.items; error = nil
        } catch { self.error = error.localizedDescription }
    }
    private func createEvent() async {
        do {
            let _: PortalEvent = try await auth.write("events", method: "POST", body:
                EventCreateInput(title: newTitle, day: newDay.apiDay, startTime: "TBC", endTime: "TBC",
                                 location: newLocation, kind: "Training", notes: newNotes),
                as: PortalEvent.self)
            newTitle = ""; newNotes = ""; await refresh()
        } catch { self.error = error.localizedDescription }
    }
    private func rsvp(_ id: Int, response: String) async {
        busyID = id; defer { busyID = nil }
        do {
            let _: RSVPReply = try await auth.write("events/\(id)/rsvp", method: "PUT",
                body: RSVPInput(response: response), as: RSVPReply.self)
            await refresh()
        } catch { self.error = error.localizedDescription }
    }
}
