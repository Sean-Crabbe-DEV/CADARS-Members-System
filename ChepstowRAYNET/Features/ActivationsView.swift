import SwiftUI

// In-app polling only. The current server has no APNs push delivery.
struct ActivationsView: View {
    @EnvironmentObject private var auth: AuthStore
    @State private var inbox: ActivationInbox?
    @State private var errorMessage: String?
    @State private var workingId: Int?

    var body: some View {
        List {
            if auth.offline {
                Label("Offline snapshot only — NOT live dispatch", systemImage: "wifi.slash")
                    .foregroundStyle(Brand.amber)
            } else {
                Label("Checks for changes every 60 seconds while open. No background push alerts.", systemImage: "info.circle")
                    .font(.caption).foregroundStyle(Brand.muted)
            }
            if let inbox {
                Section("My activations · \(inbox.unansweredCount) unanswered") {
                    if inbox.activations.isEmpty { Text("No activations addressed to your account.") }
                    ForEach(inbox.activations) { item in
                        VStack(alignment: .leading, spacing: 8) {
                            HStack {
                                Text(item.title).font(.headline)
                                Spacer()
                                if item.unanswered { Image(systemName: "circle.fill").foregroundStyle(Brand.amber) }
                            }
                            Text("\(item.kind) · \(item.priority) · \(item.status)")
                                .font(.caption).foregroundStyle(item.priority == "Immediate" ? Brand.amber : Brand.teal)
                            Text(item.message).font(.subheadline).textSelection(.enabled)
                            Text("Created (UTC): \(item.createdAt)").font(.caption2).foregroundStyle(Brand.muted)
                            Text("My response: \(item.response ?? "Not recorded")").font(.caption)
                            if item.status == "Open" && !auth.offline {
                                Menu {
                                    ForEach(["Acknowledged", "Available", "Unavailable", "En route", "On scene"], id: \.self) { value in
                                        Button(value) { Task { await respond(item.id, value) } }
                                    }
                                } label: {
                                    Label(workingId == item.id ? "Saving…" : "Respond to activation", systemImage: "checkmark.bubble")
                                }.disabled(workingId != nil)
                            }
                        }.padding(.vertical, 5)
                    }
                }
            } else { ProgressView("Loading activations…") }
            if let errorMessage { Text(errorMessage).foregroundStyle(Brand.amber) }
            Section {
                Text("Urgent operational mobilisation must follow your group's established radio and telephone procedures. An in-app activation is not a guaranteed emergency notification.")
                    .font(.caption).foregroundStyle(Brand.muted)
            }
        }
        .scrollContentBackground(.hidden).listRowBackground(Brand.surface).background(Brand.background)
        .navigationTitle("My alerts")
        .toolbar { Button { Task { await refresh() } } label: { Image(systemName: "arrow.clockwise") } }
        .task {
            await refresh()
            while !Task.isCancelled {
                try? await Task.sleep(for: .seconds(60))
                if Task.isCancelled { break }
                if !auth.offline { await refresh() }
            }
        }
        .refreshable { await refresh() }
    }
    private func refresh() async {
        do { inbox = try await auth.get("my/activations", as: ActivationInbox.self); errorMessage = nil }
        catch { errorMessage = error.localizedDescription }
    }
    private func respond(_ id: Int, _ choice: String) async {
        workingId = id; defer { workingId = nil }
        do {
            let _: ActivationReply = try await auth.write("my/activations/\(id)/respond", method: "POST",
                body: ActivationResponseInput(response: choice), as: ActivationReply.self)
            await refresh()
        } catch { errorMessage = error.localizedDescription }
    }
}

struct ControllerActivationsView: View {
    @EnvironmentObject private var auth: AuthStore
    @State private var alerts: [Activation] = []
    @State private var errorMessage: String?
    var body: some View {
        List {
            Section("Controller actions") {
                NavigationLink { CreateActivationView() } label: { Label("Issue new activation", systemImage: "bell.badge") }
            }
            Section("Issued activations") {
                ForEach(alerts) { item in
                    NavigationLink { ActivationRosterView(activation: item) } label: {
                        VStack(alignment: .leading, spacing: 3) {
                            Text(item.title).font(.headline)
                            Text("\(item.kind) · \(item.priority) · \(item.status)").font(.caption).foregroundStyle(Brand.muted)
                            Text(item.createdAt).font(.caption2).foregroundStyle(Brand.muted)
                        }
                    }
                }
            }
            if let errorMessage { Text(errorMessage).foregroundStyle(Brand.amber) }
        }.scrollContentBackground(.hidden).listRowBackground(Brand.surface).background(Brand.background)
            .navigationTitle("Activation control")
            .onAppear { Task { await refresh() } }.refreshable { await refresh() }
    }
    private func refresh() async {
        do { alerts = try await auth.get("control/activations", as: ActivationList.self).activations; errorMessage = nil }
        catch { errorMessage = error.localizedDescription }
    }
}

struct CreateActivationView: View {
    @EnvironmentObject private var auth: AuthStore
    @Environment(\.dismiss) private var dismiss
    @State private var title = ""
    @State private var detail = ""
    @State private var kind = "Standby"
    @State private var priority = "Routine"
    @State private var selectedRecipients: Set<Int> = []
    @State private var sendToAll = true
    @State private var members: [TeamMember] = []
    @State private var eventID = ""
    @State private var working = false
    @State private var errorMessage: String?
    var body: some View {
        Form {
            Section("Message") {
                TextField("Title", text: $title)
                TextField("Details and instructions", text: $detail, axis: .vertical).lineLimit(3...9)
                Picker("Type", selection: $kind) { ForEach(["Information", "Standby", "Activation", "Exercise"], id: \.self) { Text($0) } }
                Picker("Priority", selection: $priority) { ForEach(["Routine", "Priority", "Immediate"], id: \.self) { Text($0) } }
                TextField("Linked event ID (optional)", text: $eventID).keyboardType(.numberPad)
            }
            Section("Recipients") {
                Toggle("All active members", isOn: $sendToAll)
                if !sendToAll {
                    ForEach(members) { member in
                        Toggle(isOn: Binding(get: { selectedRecipients.contains(member.id) }, set: { selected in
                            if selected { selectedRecipients.insert(member.id) } else { selectedRecipients.remove(member.id) }
                        })) {
                            Text("\(member.name) \(member.callsign ?? "")")
                        }
                    }
                }
                Text("Empty recipient list on the server means ALL active non-system members.")
                    .font(.caption).foregroundStyle(Brand.amber)
            }
            if let errorMessage { Text(errorMessage).foregroundStyle(Brand.amber) }
            Button(working ? "Sending…" : "Issue activation") { Task { await send() } }
                .disabled(auth.offline || working || title.trimmingCharacters(in: .whitespaces).isEmpty || detail.trimmingCharacters(in: .whitespaces).isEmpty || (!sendToAll && selectedRecipients.isEmpty))
            Text("This creates an in-app record; it does not send a phone push, SMS or email.")
                .font(.caption).foregroundStyle(Brand.muted)
        }.scrollContentBackground(.hidden).background(Brand.background)
            .navigationTitle("New activation").task { await loadMembers() }
    }
    private func loadMembers() async {
        do { members = try await auth.get("team/members", as: ItemsReply<TeamMember>.self).items }
        catch { errorMessage = error.localizedDescription }
    }
    private func send() async {
        working = true; defer { working = false }
        do {
            let id = eventID.trimmingCharacters(in: .whitespaces)
            guard id.isEmpty || (Int(id) != nil && Int(id)! > 0) else {
                errorMessage = "Event ID must be a positive number."; return
            }
            let _: Activation = try await auth.write("control/activations", method: "POST",
                body: ActivationCreateInput(title: title, message: detail, kind: kind, priority: priority,
                    eventId: id.isEmpty ? nil : Int(id), recipientIds: sendToAll ? [] : Array(selectedRecipients).sorted()),
                as: Activation.self)
            dismiss()
        } catch { errorMessage = error.localizedDescription }
    }
}

struct ActivationRosterView: View {
    @EnvironmentObject private var auth: AuthStore
    let activation: Activation
    @State private var roster: ActivationRoster?
    @State private var errorMessage: String?
    @State private var confirmClose = false
    var body: some View {
        List {
            Section("Activation") {
                Text(activation.title).font(.headline)
                Text(activation.message).textSelection(.enabled)
                Text("\(activation.kind) · \(activation.priority)")
                Text("Status: \(roster?.activation.status ?? activation.status)")
            }
            Section("Recipient responses") {
                ForEach(roster?.recipients ?? []) { row in
                    VStack(alignment: .leading) {
                        Text("\(row.name) · \(row.callsign ?? "")").font(.headline)
                        Text("\(row.response) · \(row.respondedAt ?? "No response yet")")
                            .font(.caption).foregroundStyle(Brand.muted)
                    }
                }
            }
            if roster?.activation.status == "Open" && !auth.offline {
                Button("Close activation", role: .destructive) { confirmClose = true }
            }
            if let errorMessage { Text(errorMessage).foregroundStyle(Brand.amber) }
        }.scrollContentBackground(.hidden).listRowBackground(Brand.surface).background(Brand.background)
            .navigationTitle("Response roster")
            .task { await refresh() }.refreshable { await refresh() }
            .confirmationDialog("Close this activation?", isPresented: $confirmClose) {
                Button("Close activation", role: .destructive) { Task { await close() } }
            }
    }
    private func refresh() async {
        do { roster = try await auth.get("control/activations/\(activation.id)/responses", as: ActivationRoster.self); errorMessage = nil }
        catch { errorMessage = error.localizedDescription }
    }
    private func close() async {
        do {
            let _: ActivationCloseReply = try await auth.sendNoBody("control/activations/\(activation.id)/close", method: "POST", as: ActivationCloseReply.self)
            await refresh()
        } catch { errorMessage = error.localizedDescription }
    }
}
