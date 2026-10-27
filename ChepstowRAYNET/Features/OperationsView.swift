import SwiftUI

struct OperationsView: View {
    @EnvironmentObject private var auth: AuthStore
    var body: some View {
        List {
            if auth.offline {
                Label("Cached read-only mode. Check timestamps before operational decisions.", systemImage: "wifi.slash")
                    .foregroundStyle(Brand.amber).listRowBackground(Brand.surface)
            }
            Section("Personal") {
                NavigationLink { EventsView() } label: { Label("Calendar and RSVPs", systemImage: "calendar") }
                NavigationLink { AvailabilityView() } label: { Label("My availability", systemImage: "calendar.badge.clock") }
                NavigationLink { EquipmentView() } label: { Label("My equipment", systemImage: "antenna.radiowaves.left.and.right") }
                NavigationLink { TrainingView() } label: { Label("My training", systemImage: "checklist") }
                NavigationLink { ExperienceView() } label: { Label("My experience", systemImage: "clock.arrow.circlepath") }
                NavigationLink { ProfileView() } label: { Label("My profile", systemImage: "person.crop.circle") }
            }
            Section("Group") {
                NavigationLink { ActivationsView() } label: { Label("My activation alerts", systemImage: "bell.badge") }
                NavigationLink { ExercisesView() } label: { Label("Exercises and message pad", systemImage: "doc.text") }
                NavigationLink { GroupActionsView() } label: { Label("Group actions", systemImage: "list.bullet.clipboard") }
                NavigationLink { AlertsView() } label: { Label("Power, weather & flood alerts", systemImage: "exclamationmark.triangle") }
                NavigationLink { ManualsView() } label: { Label("Manuals / documentation", systemImage: "books.vertical") }
            }
            if auth.user?.isController == true {
                Section("Controller / admin") {
                    NavigationLink { ControllerActivationsView() } label: { Label("Issue / manage activations", systemImage: "megaphone") }
                    NavigationLink { AttendanceView() } label: { Label("Event attendance", systemImage: "person.crop.rectangle.stack") }
                    NavigationLink { TeamMembersView() } label: { Label("Member directory", systemImage: "person.3") }
                    NavigationLink { TeamAvailabilityView() } label: { Label("Team availability", systemImage: "person.2.badge.gearshape") }
                    NavigationLink { PendingReviewView() } label: { Label("Pending reviews", systemImage: "checkmark.seal") }
                }
            }
        }
        .scrollContentBackground(.hidden)
        .listRowBackground(Brand.surface)
        .background(Brand.background)
        .navigationTitle("Operations")
    }
}

struct AvailabilityView: View {
    @EnvironmentObject private var auth: AuthStore
    @State private var entries: [AvailabilityEntry] = []
    @State private var start = Date()
    @State private var end = Date()
    @State private var status = "Available"
    @State private var notes = ""
    @State private var error: String?
    @State private var busy = false
    var body: some View {
        Form {
            Section("My availability") {
                ForEach(entries) { item in
                    VStack(alignment: .leading) {
                        HStack { Text(item.status).foregroundStyle(item.status == "Available" ? Brand.teal : Brand.amber); Spacer(); Text("\(item.startDay) – \(item.endDay)").font(.caption) }
                        if let notes = item.notes, !notes.isEmpty { Text(notes).font(.caption).foregroundStyle(Brand.muted) }
                    }
                    .swipeActions { if !auth.offline { Button("Delete", role: .destructive) { Task { await delete(item.id) } } } }
                }
                if entries.isEmpty { StatusText(message: "No entries returned.") }
            }
            Section("Add availability") {
                DatePicker("From", selection: $start, displayedComponents: .date)
                DatePicker("To", selection: $end, displayedComponents: .date)
                Picker("Status", selection: $status) {
                    ForEach(["Available", "Maybe", "Unavailable"], id: \.self) { Text($0).tag($0) }
                }
                TextField("Notes (optional)", text: $notes)
                Button("Save to MIS") { Task { await save() } }.disabled(auth.offline || busy || end < Calendar.current.startOfDay(for: start))
            }
            if let error { Text(error).foregroundStyle(Brand.amber) }
        }.scrollContentBackground(.hidden).background(Brand.background)
            .navigationTitle("My availability").task { await refresh() }
            .refreshable { await refresh() }
    }
    private func refresh() async {
        do { entries = try await auth.get("me/availability", as: ItemsReply<AvailabilityEntry>.self).items; error = nil }
        catch { self.error = error.localizedDescription }
    }
    private func save() async {
        busy = true; defer { busy = false }
        do {
            let _: AvailabilityEntry = try await auth.write("me/availability", method: "POST",
                body: AvailabilityInput(startDay: start.apiDay, endDay: end.apiDay, status: status, notes: notes), as: AvailabilityEntry.self)
            notes = ""; await refresh()
        } catch { self.error = error.localizedDescription }
    }
    private func delete(_ id: Int) async {
        do { try await auth.remove("me/availability/\(id)"); await refresh() }
        catch { self.error = error.localizedDescription }
    }
}

struct EquipmentView: View {
    @EnvironmentObject private var auth: AuthStore
    @State private var entries: [EquipmentEntry] = []
    @State private var category = "Handheld radio"
    @State private var model = ""
    @State private var band = ""
    @State private var modes = ""
    @State private var power = ""
    @State private var notes = ""
    @State private var deployable = true
    @State private var error: String?
    var body: some View {
        Form {
            Section("My equipment") {
                ForEach(entries) { item in
                    VStack(alignment: .leading, spacing: 3) {
                        Text(item.makeModel).font(.headline)
                        Text("\(item.category) · \(item.band ?? "") · \(item.modes ?? "")")
                            .font(.caption).foregroundStyle(Brand.muted)
                        Text(item.deployable ? "Deployable" : "Not deployable").font(.caption)
                    }.swipeActions { if !auth.offline { Button("Delete", role: .destructive) { Task { await delete(item.id) } } } }
                }
            }
            Section("Add equipment") {
                TextField("Category", text: $category)
                TextField("Make and model", text: $model)
                TextField("Band (semicolon separated)", text: $band)
                TextField("Modes (semicolon separated)", text: $modes)
                TextField("Power", text: $power)
                Toggle("Deployable", isOn: $deployable)
                TextField("Notes", text: $notes)
                Button("Save equipment") { Task { await save() } }
                    .disabled(auth.offline || model.trimmingCharacters(in: .whitespaces).isEmpty || category.isEmpty)
            }
            if let error { Text(error).foregroundStyle(Brand.amber) }
        }.scrollContentBackground(.hidden).background(Brand.background)
            .navigationTitle("My equipment").task { await refresh() }.refreshable { await refresh() }
    }
    private func refresh() async {
        do { entries = try await auth.get("me/equipment", as: ItemsReply<EquipmentEntry>.self).items; error = nil }
        catch { self.error = error.localizedDescription }
    }
    private func save() async {
        do {
            let _: EquipmentEntry = try await auth.write("me/equipment", method: "POST",
                body: EquipmentInput(category: category, makeModel: model, band: band, modes: modes,
                                     power: power, deployable: deployable, notes: notes), as: EquipmentEntry.self)
            model = ""; notes = ""; await refresh()
        } catch { self.error = error.localizedDescription }
    }
    private func delete(_ id: Int) async {
        do { try await auth.remove("me/equipment/\(id)"); await refresh() }
        catch { self.error = error.localizedDescription }
    }
}
