import SwiftUI

private struct TrainingInput: Encodable { let evidence: String }
private struct ExperienceInput: Encodable {
    let day: String
    let title: String
    let dutyRole: String
    let hours: Double
    let notes: String
}
private struct ActionInput: Encodable {
    let title: String
    let description: String
    let category: String
}
private struct ActionNote: Encodable { let note: String }
private struct ProfileInput: Encodable {
    let name: String
    let callsign: String
    let phone: String
    let personalEmail: String
    let raynetEmail: String
}

struct TrainingView: View {
    @EnvironmentObject private var auth: AuthStore
    @State private var modules: [TrainingModule] = []
    @State private var evidence: [Int: String] = [:]
    @State private var error: String?
    var body: some View {
        List {
            ForEach(modules) { item in
                VStack(alignment: .leading, spacing: 9) {
                    Text(item.title).font(.headline)
                    if let category = item.category { Text(category).font(.caption).foregroundStyle(Brand.teal) }
                    if let description = item.description { Text(description).font(.caption) }
                    Text("Status: \(item.record?.status ?? "Not submitted")")
                        .foregroundStyle(item.record?.status == "verified" ? Brand.teal : Brand.muted)
                    if item.record?.status != "verified" {
                        TextField("Evidence", text: Binding(get: { evidence[item.id] ?? item.record?.evidence ?? "" },
                                                            set: { evidence[item.id] = $0 }), axis: .vertical)
                            .lineLimit(2...4)
                        Button("Submit evidence") { Task { await submit(item.id) } }
                            .disabled(auth.offline || (evidence[item.id] ?? item.record?.evidence ?? "").isEmpty)
                    }
                }.padding(.vertical, 7).listRowBackground(Brand.surface)
            }
            if let error { Text(error).foregroundStyle(Brand.amber) }
        }.scrollContentBackground(.hidden).background(Brand.background).navigationTitle("My training")
            .task { await refresh() }.refreshable { await refresh() }
    }
    private func refresh() async {
        do { modules = try await auth.get("me/training", as: ItemsReply<TrainingModule>.self).items; error = nil }
        catch { self.error = error.localizedDescription }
    }
    private func submit(_ id: Int) async {
        guard let evidence = evidence[id], !evidence.isEmpty else { return }
        do {
            let _: TrainingModule = try await auth.write("me/training/\(id)", method: "POST", body: TrainingInput(evidence: evidence), as: TrainingModule.self)
            await refresh()
        } catch { self.error = error.localizedDescription }
    }
}

struct ExperienceView: View {
    @EnvironmentObject private var auth: AuthStore
    @State private var records: [ExperienceEntry] = []
    @State private var day = Date()
    @State private var title = ""
    @State private var dutyRole = ""
    @State private var hours = "1.0"
    @State private var notes = ""
    @State private var verifiedHours = 0.0
    @State private var error: String?
    var body: some View {
        Form {
            Section("Recorded duties · \(verifiedHours.formatted()) verified hours") {
                ForEach(records) { item in
                    VStack(alignment: .leading) {
                        Text(item.title).font(.headline)
                        Text("\(item.day) · \(item.dutyRole) · \(item.hours.formatted()) h")
                            .font(.caption)
                        Text(item.verified ? "Verified" : "Awaiting verification")
                            .font(.caption).foregroundStyle(item.verified ? Brand.teal : Brand.amber)
                    }
                }
            }
            Section("Submit experience") {
                DatePicker("Date", selection: $day, displayedComponents: .date)
                TextField("Activity title", text: $title)
                TextField("Duty role", text: $dutyRole)
                TextField("Hours", text: $hours).keyboardType(.decimalPad)
                TextField("Notes", text: $notes, axis: .vertical).lineLimit(2...4)
                Button("Submit for verification") { Task { await submit() } }
                    .disabled(auth.offline || title.isEmpty || dutyRole.isEmpty || (Double(hours) ?? 0) <= 0)
            }
            if let error { Text(error).foregroundStyle(Brand.amber) }
        }.scrollContentBackground(.hidden).background(Brand.background)
            .navigationTitle("My experience").task { await refresh() }.refreshable { await refresh() }
    }
    private func refresh() async {
        do {
            let result: ExperienceReply = try await auth.get("me/experience", as: ExperienceReply.self)
            records = result.items; verifiedHours = result.verifiedHours; error = nil
        } catch { self.error = error.localizedDescription }
    }
    private func submit() async {
        guard let hoursValue = Double(hours), hoursValue > 0, hoursValue <= 72 else { return }
        do {
            let _: ExperienceEntry = try await auth.write("me/experience", method: "POST",
                body: ExperienceInput(day: day.apiDay, title: title, dutyRole: dutyRole, hours: hoursValue, notes: notes), as: ExperienceEntry.self)
            title = ""; dutyRole = ""; notes = ""; await refresh()
        } catch { self.error = error.localizedDescription }
    }
}

struct GroupActionsView: View {
    @EnvironmentObject private var auth: AuthStore
    @State private var actions: [ActionEntry] = []
    @State private var title = ""
    @State private var description = ""
    @State private var category = "General"
    @State private var error: String?
    var body: some View {
        Form {
            Section("Group actions") {
                ForEach(actions) { item in
                    NavigationLink {
                        ActionDetailsView(item: item)
                    } label: {
                        VStack(alignment: .leading) {
                            Text(item.title).font(.headline)
                            Text("\(item.category ?? "General") · \(item.status ?? "Unknown") · \(item.priority ?? "Normal")")
                                .font(.caption).foregroundStyle(Brand.muted)
                        }
                    }
                }
            }
            Section("Create group action") {
                TextField("Title", text: $title)
                TextField("Description", text: $description, axis: .vertical).lineLimit(2...5)
                Picker("Category", selection: $category) {
                    ForEach(["General", "Training", "Equipment", "Exercise", "Communications", "Administration", "Operational Readiness"], id: \.self) { Text($0).tag($0) }
                }
                Button("Create action") { Task { await create() } }.disabled(auth.offline || title.isEmpty)
            }
            if let error { Text(error).foregroundStyle(Brand.amber) }
        }.scrollContentBackground(.hidden).background(Brand.background).navigationTitle("Group actions")
            .task { await refresh() }.refreshable { await refresh() }
    }
    private func refresh() async {
        do { actions = try await auth.get("group-actions", as: ItemsReply<ActionEntry>.self).items; error = nil }
        catch { self.error = error.localizedDescription }
    }
    private func create() async {
        do {
            let _: NewActionReply = try await auth.write("group-actions", method: "POST", body: ActionInput(title: title, description: description, category: category), as: NewActionReply.self)
            title = ""; description = ""; await refresh()
        } catch { self.error = error.localizedDescription }
    }
}
private struct NewActionReply: Decodable { let id: Int; let status: String }
private struct ActionUpdateReply: Decodable { let id: Int; let ok: Bool }
struct ActionDetailsView: View {
    @EnvironmentObject private var auth: AuthStore
    let item: ActionEntry
    @State private var note = ""
    @State private var feedback: String?
    var body: some View {
        Form {
            Section(item.title) {
                Text(item.description ?? "")
                LabeledContent("Status", value: item.status ?? "Unknown")
                LabeledContent("Priority", value: item.priority ?? "Unknown")
                if let due = item.dueDate { LabeledContent("Due", value: due) }
            }
            Section("Add update note") {
                TextField("Update", text: $note, axis: .vertical).lineLimit(2...6)
                Button("Post note") { Task { await submit() } }.disabled(auth.offline || note.isEmpty)
                if let feedback { Text(feedback).foregroundStyle(Brand.muted) }
            }
        }.scrollContentBackground(.hidden).background(Brand.background).navigationTitle("Action #\(item.id)")
    }
    private func submit() async {
        do {
            let _: ActionUpdateReply = try await auth.write("group-actions/\(item.id)/updates", method: "POST",
                body: ActionNote(note: note), as: ActionUpdateReply.self)
            note = ""; feedback = "Update sent to MIS."
        } catch { feedback = error.localizedDescription }
    }
}

struct ProfileView: View {
    @EnvironmentObject private var auth: AuthStore
    @State private var name = ""
    @State private var callsign = ""
    @State private var phone = ""
    @State private var personalEmail = ""
    @State private var raynetEmail = ""
    @State private var feedback: String?
    var body: some View {
        Form {
            Section("My details") {
                TextField("Name", text: $name)
                TextField("Callsign", text: $callsign).textInputAutocapitalization(.characters)
                TextField("Phone", text: $phone).keyboardType(.phonePad)
                TextField("Personal email", text: $personalEmail).textInputAutocapitalization(.never).keyboardType(.emailAddress)
                TextField("RAYNET email", text: $raynetEmail).textInputAutocapitalization(.never).keyboardType(.emailAddress)
                LabeledContent("Role", value: auth.user?.role ?? "Unknown")
                Button("Save profile") { Task { await save() } }.disabled(auth.offline || name.isEmpty)
            }
            if let feedback { Text(feedback).foregroundStyle(Brand.muted) }
        }.scrollContentBackground(.hidden).background(Brand.background).navigationTitle("My profile")
            .onAppear { load() }
    }
    private func load() {
        name = auth.user?.name ?? ""; callsign = auth.user?.callsign ?? ""; phone = auth.user?.phone ?? ""
        personalEmail = auth.user?.personalEmail ?? ""; raynetEmail = auth.user?.raynetEmail ?? ""
    }
    private func save() async {
        do {
            let _: Member = try await auth.write("me", method: "PATCH",
                body: ProfileInput(name: name, callsign: callsign, phone: phone,
                                   personalEmail: personalEmail, raynetEmail: raynetEmail), as: Member.self)
            feedback = "Saved. Account details will update on the next session refresh."
        } catch { feedback = error.localizedDescription }
    }
}
