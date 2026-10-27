import SwiftUI
import PDFKit

struct ExercisesView: View {
    @EnvironmentObject private var auth: AuthStore
    @State private var exercises: [ExerciseSummary] = []
    @State private var errorMessage: String?
    var body: some View {
        List {
            if auth.offline { Label("Cached briefings may be outdated", systemImage: "wifi.slash").foregroundStyle(Brand.amber) }
            ForEach(exercises) { item in
                NavigationLink { ExerciseDetailView(eventID: item.eventId) } label: {
                    VStack(alignment: .leading, spacing: 3) {
                        Text(item.title).font(.headline)
                        Text("\(item.day) · \(item.location ?? "")").font(.caption)
                        Text("Briefing: \(item.briefStatus)").font(.caption).foregroundStyle(Brand.teal)
                    }
                }
            }
            if exercises.isEmpty && errorMessage == nil { Text("No accessible exercises have been returned.") }
            if let errorMessage { Text(errorMessage).foregroundStyle(Brand.amber) }
        }
        .scrollContentBackground(.hidden).listRowBackground(Brand.surface).background(Brand.background)
        .navigationTitle("Exercises")
        .task { await refresh() }.refreshable { await refresh() }
    }
    private func refresh() async {
        do { exercises = try await auth.get("exercises", as: ExerciseList.self).exercises; errorMessage = nil }
        catch { errorMessage = error.localizedDescription }
    }
}

struct ExerciseDetailView: View {
    @EnvironmentObject private var auth: AuthStore
    let eventID: Int
    @State private var detail: ExerciseDetail?
    @State private var errorMessage: String?
    @State private var attachment: ExercisePDF?
    @State private var showBriefEditor = false
    @State private var showUpdateEditor = false
    @State private var showMessageEditor = false
    var body: some View {
        List {
            if let detail {
                Section("Exercise information") {
                    Text(detail.event.title).font(.headline)
                    Text("\(detail.event.day) · \(detail.event.startTime ?? "TBC")")
                    Text(detail.event.location ?? "Location not specified")
                    Text("Briefing: \(detail.status)").foregroundStyle(detail.status == "Published" ? Brand.teal : Brand.amber)
                }
                Section("Briefing") {
                    Text(detail.brief.isEmpty ? "No briefing recorded" : detail.brief)
                        .textSelection(.enabled)
                    if auth.user?.isController == true && !auth.offline {
                        Button("Edit / publish briefing") { showBriefEditor = true }
                    }
                }
                Section("Control updates") {
                    if detail.updates.isEmpty { Text("No updates") }
                    ForEach(detail.updates) { item in
                        VStack(alignment: .leading, spacing: 4) {
                            Text(item.body).textSelection(.enabled)
                            Text(item.createdAt ?? "").font(.caption2).foregroundStyle(Brand.muted)
                        }
                    }
                    if auth.user?.isController == true && detail.status == "Published" && !auth.offline {
                        Button("Add control update") { showUpdateEditor = true }
                    }
                }
                Section("RAYNET message pad") {
                    if detail.messages.isEmpty { Text("No recorded messages") }
                    ForEach(detail.messages) { record in
                        NavigationLink { ExerciseMessageView(eventID: eventID, item: record) } label: {
                            VStack(alignment: .leading) {
                                Text("\(record.messageNumber) · \(record.precedence)").font(.headline)
                                Text("\(record.messageFrom) → \(record.messageTo) · \(record.dtg)")
                                    .font(.caption).foregroundStyle(Brand.muted)
                            }
                        }
                    }
                    if auth.user?.isController == true && !auth.offline {
                        Button("Record new message") { showMessageEditor = true }
                    }
                }
            } else { ProgressView("Loading exercise…") }
            if let errorMessage { Text(errorMessage).foregroundStyle(Brand.amber) }
        }
        .scrollContentBackground(.hidden).listRowBackground(Brand.surface).background(Brand.background)
        .navigationTitle("Exercise #\(eventID)")
        .task { await refresh() }.refreshable { await refresh() }
        .sheet(item: $attachment) { item in
            NavigationStack {
                ExercisePDFView(data: item.data).navigationTitle(item.title)
                    .toolbar { Button("Done") { attachment = nil } }
            }
        }
        .sheet(isPresented: $showBriefEditor, onDismiss: { Task { await refresh() } }) {
            NavigationStack { BriefEditor(eventID: eventID, original: detail?.brief ?? "", status: detail?.status ?? "Draft") }
        }
        .sheet(isPresented: $showUpdateEditor, onDismiss: { Task { await refresh() } }) {
            NavigationStack { ExerciseUpdateEditor(eventID: eventID) }
        }
        .sheet(isPresented: $showMessageEditor, onDismiss: { Task { await refresh() } }) {
            NavigationStack { MessageEditor(eventID: eventID) }
        }
    }
    private func refresh() async {
        do { detail = try await auth.get("exercises/\(eventID)", as: ExerciseDetail.self); errorMessage = nil }
        catch { errorMessage = error.localizedDescription }
    }
}

private struct ExercisePDF: Identifiable {
    let id = UUID()
    let title: String
    let data: Data
}
private struct ExercisePDFView: UIViewRepresentable {
    let data: Data
    func makeUIView(context: Context) -> PDFView {
        let view = PDFView()
        view.autoScales = true
        view.document = PDFDocument(data: data)
        return view
    }
    func updateUIView(_ view: PDFView, context: Context) {}
}

struct ExerciseMessageView: View {
    @EnvironmentObject private var auth: AuthStore
    let eventID: Int
    let item: ExerciseMessage
    @State private var pdf: Data?
    @State private var showPDF = false
    @State private var errorMessage: String?
    var body: some View {
        List {
            Section("Routing") {
                field("Serial", item.messageNumber)
                field("Precedence", item.precedence)
                field("Origin DTG", item.dtg)
                field("From", item.messageFrom)
                field("To", item.messageTo)
                field("Information", item.information)
            }
            Section("Message") { Text(item.messageBody).textSelection(.enabled) }
            Section("Origin and release") {
                field("Originator", item.originator)
                field("Released by", item.releasedBy)
            }
            Section("Receive / transmit log") {
                field("Received by", item.receivedBy)
                field("Received DTG", item.receivedDtg)
                field("Received method", item.receivedMethod)
                field("Transmitted by", item.transmittedBy)
                field("Transmitted DTG", item.transmittedDtg)
                field("Transmitted method", item.transmittedMethod)
            }
            if item.hasPdf {
                Section {
                    Button("View attached message-pad PDF") { Task { await loadPDF() } }
                        .disabled(auth.offline)
                }
            }
            if let errorMessage { Text(errorMessage).foregroundStyle(Brand.amber) }
        }.scrollContentBackground(.hidden).listRowBackground(Brand.surface).background(Brand.background)
            .navigationTitle("Message \(item.messageNumber)")
            .sheet(isPresented: $showPDF) {
                if let pdf {
                    NavigationStack { ExercisePDFView(data: pdf).navigationTitle("Message PDF")
                        .toolbar { Button("Done") { showPDF = false; self.pdf = nil } } }
                }
            }
    }
    private func field(_ label: String, _ value: String) -> some View {
        LabeledContent(label, value: value.isEmpty ? "—" : value).textSelection(.enabled)
    }
    private func loadPDF() async {
        do {
            pdf = try await auth.pdfData(at: "exercises/\(eventID)/messages/\(item.id)/pdf")
            showPDF = true
        } catch { errorMessage = error.localizedDescription }
    }
}

struct BriefEditor: View {
    @EnvironmentObject private var auth: AuthStore
    @Environment(\.dismiss) private var dismiss
    let eventID: Int
    @State private var brief: String
    @State private var status: String
    @State private var errorMessage: String?
    @State private var busy = false
    init(eventID: Int, original: String, status: String) {
        self.eventID = eventID
        _brief = State(initialValue: original)
        _status = State(initialValue: status)
    }
    var body: some View {
        Form {
            Section("Instructions") {
                TextEditor(text: $brief).frame(minHeight: 200)
                Picker("Status", selection: $status) { Text("Draft").tag("Draft"); Text("Published").tag("Published") }
            }
            if let errorMessage { Text(errorMessage).foregroundStyle(Brand.amber) }
            Button("Save briefing") { Task { await save() } }
                .disabled(auth.offline || busy || (status == "Published" && brief.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty))
        }.navigationTitle("Exercise briefing")
            .toolbar { Button("Cancel") { dismiss() } }
    }
    private func save() async {
        busy = true; defer { busy = false }
        do {
            let _: ExerciseBriefReply = try await auth.write("control/exercises/\(eventID)/brief", method: "PUT",
                body: ExerciseBriefInput(brief: brief, status: status), as: ExerciseBriefReply.self)
            dismiss()
        } catch { errorMessage = error.localizedDescription }
    }
}

struct ExerciseUpdateEditor: View {
    @EnvironmentObject private var auth: AuthStore
    @Environment(\.dismiss) private var dismiss
    let eventID: Int
    @State private var bodyText = ""
    @State private var errorMessage: String?
    var body: some View {
        Form {
            TextField("Control update", text: $bodyText, axis: .vertical).lineLimit(4...10)
            Button("Publish update") { Task { await send() } }
                .disabled(auth.offline || bodyText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
            if let errorMessage { Text(errorMessage).foregroundStyle(Brand.amber) }
        }.navigationTitle("Control update")
            .toolbar { Button("Cancel") { dismiss() } }
    }
    private func send() async {
        do {
            let _: ExerciseUpdateReply = try await auth.write("control/exercises/\(eventID)/updates", method: "POST",
                body: ExerciseUpdateInput(body: bodyText), as: ExerciseUpdateReply.self)
            dismiss()
        } catch { errorMessage = error.localizedDescription }
    }
}

struct MessageEditor: View {
    @EnvironmentObject private var auth: AuthStore
    @Environment(\.dismiss) private var dismiss
    let eventID: Int
    @State private var serial = ""
    @State private var precedence = "Routine"
    @State private var dtg = ""
    @State private var messageFrom = ""
    @State private var messageTo = ""
    @State private var information = ""
    @State private var message = ""
    @State private var originator = ""
    @State private var releasedBy = ""
    @State private var receivedBy = ""
    @State private var receivedDTG = ""
    @State private var receivedMethod = ""
    @State private var transmittedBy = ""
    @State private var transmittedDTG = ""
    @State private var transmittedMethod = ""
    @State private var errorMessage: String?
    @State private var working = false
    private var validSerial: Bool {
        !serial.isEmpty && serial.count <= 64 && serial.unicodeScalars.allSatisfy {
            CharacterSet(charactersIn: "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.-").contains($0)
        }
    }
    var body: some View {
        Form {
            Section("Message identification") {
                TextField("Serial (e.g. SED0001)", text: $serial)
                    .textInputAutocapitalization(.characters)
                Picker("Precedence", selection: $precedence) { ForEach(["Routine", "Priority", "Immediate"], id: \.self) { Text($0) } }
                TextField("Origin DTG", text: $dtg)
                TextField("From", text: $messageFrom)
                TextField("To", text: $messageTo)
                TextField("Information", text: $information)
            }
            Section("Message body") {
                TextField("Message text", text: $message, axis: .vertical).lineLimit(4...12)
            }
            Section("Release") {
                TextField("Originator", text: $originator)
                TextField("Released by", text: $releasedBy)
            }
            Section("Reception") {
                TextField("Received by", text: $receivedBy)
                TextField("Received DTG", text: $receivedDTG)
                TextField("Received method", text: $receivedMethod)
            }
            Section("Transmission") {
                TextField("Transmitted by", text: $transmittedBy)
                TextField("Transmitted DTG", text: $transmittedDTG)
                TextField("Transmitted method", text: $transmittedMethod)
            }
            if let errorMessage { Text(errorMessage).foregroundStyle(Brand.amber) }
            Button(working ? "Saving…" : "Record message") { Task { await save() } }
                .disabled(auth.offline || working || !validSerial || message.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
            Text("Attachments can only be uploaded using the website in v0.3.4.1. Message serials must be unique within each exercise.")
                .font(.caption).foregroundStyle(Brand.muted)
        }.navigationTitle("New message pad").toolbar { Button("Cancel") { dismiss() } }
    }
    private func save() async {
        working = true; defer { working = false }
        do {
            let _: ExerciseMessage = try await auth.write("control/exercises/\(eventID)/messages", method: "POST",
                body: ExerciseMessageInput(messageNumber: serial, precedence: precedence, dtg: dtg,
                    messageFrom: messageFrom, messageTo: messageTo, information: information, messageBody: message,
                    originator: originator, releasedBy: releasedBy, receivedBy: receivedBy,
                    receivedDtg: receivedDTG, receivedMethod: receivedMethod, transmittedBy: transmittedBy,
                    transmittedDtg: transmittedDTG, transmittedMethod: transmittedMethod), as: ExerciseMessage.self)
            dismiss()
        } catch { errorMessage = error.localizedDescription }
    }
}
