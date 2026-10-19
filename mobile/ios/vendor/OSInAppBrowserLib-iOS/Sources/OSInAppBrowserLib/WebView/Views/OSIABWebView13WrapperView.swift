import SwiftUI
import UIKit

/// View that manages which view to present, depending if the page load was successful or not or is being loaded.
@available(iOS, deprecated: 14.0, message: "Use OSIABWebViewWrapperView for iOS 14.0+")
struct OSIABWebView13WrapperView: View {
    /// View Model containing all the customisable elements.
    @ObservedObject private var model: OSIABWebViewModel
    
    /// Constructor method.
    /// - Parameter model: View Model containing all the customisable elements.
    init(_ model: OSIABWebViewModel) {
        self._model = ObservedObject(wrappedValue: model)
    }
    
    var body: some View {
        ZStack {
            OSIABWebView(model)
                .edgesIgnoringSafeArea(model.toolbarPosition == .bottom ? [] : .bottom)
            if model.isLoading {
                OSIABActivityIndicator(isAnimating: .constant(true), style: .large)
            }
        }
    }
}

private struct OSIABActivityIndicator: UIViewRepresentable {
    @Binding private var isAnimating: Bool
    private let style: UIActivityIndicatorView.Style
    
    init(isAnimating: Binding<Bool>, style: UIActivityIndicatorView.Style) {
        self._isAnimating = isAnimating
        self.style = style
    }
    
    func makeUIView(context: UIViewRepresentableContext<OSIABActivityIndicator>) -> UIActivityIndicatorView {
        return UIActivityIndicatorView(style: style)
    }
    
    func updateUIView(_ uiView: UIActivityIndicatorView, context: UIViewRepresentableContext<OSIABActivityIndicator>) {
        if self.isAnimating {
            uiView.startAnimating()
        } else {
            uiView.stopAnimating()
        }
    }
}

// MARK: - OSIABViewModel's constructor accelerator.
private extension OSIABWebViewModel {
    convenience init(toolbarPosition: OSIABToolbarPosition = .defaultValue) {
        let configurationModel = OSIABWebViewConfigurationModel()
        self.init(
            url: .init(string: "https://outsystems.com")!,
            webViewConfiguration: configurationModel.toWebViewConfiguration(),
            uiModel: .init(toolbarPosition: toolbarPosition),
            callbackHandler: .init(
                onDelegateURL: { _ in },
                onDelegateAlertController: { _ in },
                onBrowserPageLoad: {},
                onBrowserClosed: { _ in },
                onBrowserPageNavigationCompleted: { _ in }
            )
        )
    }
    
    convenience init(url: String) {
        let configurationModel = OSIABWebViewConfigurationModel()
        self.init(
            url: .init(string: url)!,
            webViewConfiguration: configurationModel.toWebViewConfiguration(),
            uiModel: .init(),
            callbackHandler: .init(
                onDelegateURL: { _ in },
                onDelegateAlertController: { _ in },
                onBrowserPageLoad: {},
                onBrowserClosed: { _ in },
                onBrowserPageNavigationCompleted:  { _ in }
            )
        )
    }
}

struct OSIABWebView13WrapperView_Previews: PreviewProvider {
    static var previews: some View {
        
        // Default - Light Mode
        OSIABWebView13WrapperView(.init())
        
        // Default - Dark Mode
        OSIABWebView13WrapperView(.init())
            .preferredColorScheme(.dark)
        
        // Bottom Toolbar Defined
        OSIABWebView13WrapperView(.init(toolbarPosition: .bottom))
        
        // Error View - Light mode
        OSIABWebView13WrapperView(.init(url: "https://outsystems/"))
        
        // Error View - Dark mode
        OSIABWebView13WrapperView(.init(url: "https://outsystems/"))
            .preferredColorScheme(.dark)
    }
}
