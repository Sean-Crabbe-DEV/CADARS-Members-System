import SwiftUI
import PDFKit

struct TeamMembersView: View {
    @EnvironmentObject private var auth: AuthStore
    @State private var members: [TeamMember] = []
    @State private var error: String?
    var body: some View {
        List {
            ForEach(members) { member in
                VStack(alignment: .leading) {
                    Text(member.name).font(.headline)
                    Text("\(member.callsign ?? "No callsign") · \(member.role)")
                        .foregroundStyle(Brand.muted).font(.subheadline)
                }.listRowBackground(Brand.surface)
            }
            if let error { Text(error).foregroundStyle(Brand.amber) }
        }.scrollContentBackground(.hidden).background(Brand.background)
            .navigationTitle("Member directory").task { await refresh() }.refreshable { await refresh() }
    }
    private func refresh() async {
        do { members = try await auth.get("team/members", as: ItemsReply<TeamMember>.self).items; error = nil }
        catch { self.error = error.localizedDescription }
    }
}

struct TeamAvailabilityView: View {
    @EnvironmentObject private var auth: AuthStore
    @State private var entries: [TeamAvailability] = []
    @State private var error: String?
    var body: some View {
        List {
            ForEach(entries) { entry in
                VStack(alignment: .leading) {
                    Text("\(entry.member.name) · \(entry.status)").font(.headline)
                    Text("\(entry.startDay) – \(entry.endDay)").font(.caption).foregroundStyle(Brand.muted)
                    if let notes = entry.notes, !notes.isEmpty { Text(notes).font(.caption) }
                }.listRowBackground(Brand.surface)
            }
            if let error { Text(error).foregroundStyle(Brand.amber) }
        }.scrollContentBackground(.hidden).background(Brand.background)
            .navigationTitle("Team availability").task { await refresh() }.refreshable { await refresh() }
    }
    private func refresh() async {
        do { entries = try await auth.get("team/availability", as: ItemsReply<TeamAvailability>.self).items; error = nil }
        catch { self.error = error.localizedDescription }
    }
}

struct PendingReviewView: View {
    @EnvironmentObject private var auth: AuthStore
    @State private var training: [PendingTraining] = []
    @State private var experience: [ExperienceEntry] = []
    @State private var error: String?
    var body: some View {
        List {
            Section("Training evidence") {
                ForEach(training) { item in
                    VStack(alignment: .leading) {
                        Text("Record #\(item.id) · Member #\(item.userId) · Module #\(item.moduleId)").font(.headline)
                        Text(item.evidence ?? "").font(.caption)
                        HStack {
                            Button("Verify") { Task { await review(item.id, decision: "verified") } }
                            Button("Reject", role: .destructive) { Task { await review(item.id, decision: "rejected") } }
                        }.buttonStyle(.bordered).disabled(auth.offline)
                    }
                }
            }
            Section("Experience awaiting verification") {
                ForEach(experience) { item in
                    VStack(alignment: .leading) {
                        Text("\(item.title) · \(item.day)").font(.headline)
                        Text("\(item.dutyRole) · \(item.hours.formatted()) hours").font(.caption)
                        Button("Verify experience") { Task { await verifyExperience(item.id) } }
                            .disabled(auth.offline)
                    }
                }
            }
            if let error { Text(error).foregroundStyle(Brand.amber) }
        }.scrollContentBackground(.hidden).background(Brand.background).listRowBackground(Brand.surface)
            .navigationTitle("Pending reviews").task { await refresh() }.refreshable { await refresh() }
    }
    private func refresh() async {
        do { training = try await auth.get("team/training/pending", as: ItemsReply<PendingTraining>.self).items }
        catch { self.error = "Training: \(error.localizedDescription)" }
        do { experience = try await auth.get("team/experience/pending", as: ItemsReply<ExperienceEntry>.self).items }
        catch { self.error = "Experience: \(error.localizedDescription)" }
    }
    private func review(_ id: Int, decision: String) async {
        do {
            let _: TrainingReviewReply = try await auth.write("team/training/\(id)/review", method: "POST",
                body: TrainingReviewInput(decision: decision), as: TrainingReviewReply.self)
            await refresh()
        } catch { self.error = error.localizedDescription }
    }
    private func verifyExperience(_ id: Int) async {
        do {
            let _: ExperienceReviewReply = try await auth.sendNoBody("team/experience/\(id)/verify", method: "POST", as: ExperienceReviewReply.self)
            await refresh()
        } catch { self.error = error.localizedDescription }
    }
}

private struct PDFPreview: Identifiable {
    let id = UUID()
    let title: String
    let data: Data
}
private struct PDFCanvas: UIViewRepresentable {
    let data: Data
    func makeUIView(context: Context) -> PDFView {
        let view = PDFView()
        view.autoScales = true
        view.backgroundColor = UIColor(Brand.background)
        view.document = PDFDocument(data: data)
        return view
    }
    func updateUIView(_ view: PDFView, context: Context) { }
}
struct ManualsView: View {
    @EnvironmentObject private var auth: AuthStore
    @State private var manuals: [ManualEntry] = []
    @State private var error: String?
    @State private var preview: PDFPreview?
    var body: some View {
        List {
            ForEach(manuals) { item in
                Button {
                    Task { await open(item) }
                } label: {
                    VStack(alignment: .leading) {
                        Label(item.title, systemImage: "doc.richtext").font(.headline)
                        if let info = item.description { Text(info).font(.caption).foregroundStyle(Brand.muted) }
                    }
                }.disabled(auth.offline)
                .listRowBackground(Brand.surface)
            }
            if auth.offline { StatusText(message: "Manual catalogue may be cached, but PDFs are not stored offline.") }
            if let error { Text(error).foregroundStyle(Brand.amber) }
        }.scrollContentBackground(.hidden).background(Brand.background)
            .navigationTitle("Manuals").task { await refresh() }
            .sheet(item: $preview) { item in
                NavigationStack {
                    PDFCanvas(data: item.data).ignoresSafeArea(edges: .bottom)
                        .navigationTitle(item.title)
                        .toolbar { Button("Done") { preview = nil } }
                }
            }
    }
    private func refresh() async {
        do { manuals = try await auth.get("manuals", as: ItemsReply<ManualEntry>.self).items; error = nil }
        catch { self.error = error.localizedDescription }
    }
    private func open(_ manual: ManualEntry) async {
        do {
            let data = try await auth.pdfData(at: "manuals/\(manual.id)/download")
            preview = PDFPreview(title: manual.title, data: data)
            error = nil
        } catch { self.error = error.localizedDescription }
    }
}
