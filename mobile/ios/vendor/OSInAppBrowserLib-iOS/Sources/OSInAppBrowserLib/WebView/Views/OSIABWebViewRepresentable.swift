import SwiftUI
import WebKit

/// Makes `WKWebView` available on SwiftUI.
struct OSIABWebViewRepresentable: UIViewRepresentable {
    /// The WebView to integrate on the SwiftUI view.
    private let webView: WKWebView
    
    /// Constructor method.
    /// - Parameter webView: The WebView to integrate on the SwiftUI view.
    init(_ webView: WKWebView) {
        self.webView = webView
    }
    
    func makeUIView(context: Context) -> WKWebView {
        self.webView
    }
    
    func updateUIView(_ uiView: WKWebView, context: Context) {
        // Empty method.
        // This is required by the protocol.
    }
}
