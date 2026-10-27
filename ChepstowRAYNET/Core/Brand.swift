import SwiftUI

enum Brand {
    static let background = Color(red: 11/255, green: 20/255, blue: 32/255)
    static let surface = Color(red: 17/255, green: 30/255, blue: 44/255)
    static let surface2 = Color(red: 21/255, green: 37/255, blue: 53/255)
    static let line = Color(red: 37/255, green: 55/255, blue: 73/255)
    static let teal = Color(red: 61/255, green: 224/255, blue: 204/255)
    static let amber = Color(red: 246/255, green: 182/255, blue: 81/255)
    static let muted = Color(red: 146/255, green: 165/255, blue: 180/255)
    static let text = Color(red: 234/255, green: 241/255, blue: 247/255)
}

struct BrandCard<Content: View>: View {
    @ViewBuilder var content: Content
    var body: some View {
        VStack(alignment: .leading, spacing: 12) { content }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(16)
            .background(Brand.surface, in: RoundedRectangle(cornerRadius: 13))
            .overlay(RoundedRectangle(cornerRadius: 13).stroke(Brand.line, lineWidth: 1))
    }
}

struct StatusText: View {
    let message: String
    var body: some View { Text(message).font(.footnote).foregroundStyle(Brand.muted) }
}
