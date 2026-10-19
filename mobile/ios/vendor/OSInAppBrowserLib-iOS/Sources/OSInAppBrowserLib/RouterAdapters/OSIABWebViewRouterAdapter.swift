import SwiftUI

/// Adapter that makes the required calls so that an `WKWebView` implementation can perform the Web View routing.
/// This is done via a customisable interface.
public class OSIABWebViewRouterAdapter: NSObject, OSIABRouter {
    public typealias ReturnType = UIViewController
    
    /// Object that contains the value to format the visual presentation.
    private let options: OSIABWebViewOptions
    /// Custom headers to be used by the WebView.
    private let customHeaders: [String: String]?
    /// Object that manages the browser's cache
    private let cacheManager: OSIABCacheManager
    /// Object that manages all the callbacks available for the WebView.
    private let callbackHandler: OSIABWebViewCallbackHandler
    
    /// Constructor method.
    /// - Parameters:
    ///   - options: Object that contains the value to format the visual presentation.
    ///   - customHeaders: Custom headers to be used by the WebView. `nil` is provided in case of no value.
    ///   - cacheManager: Object that manages the browser's cache
    ///   - callbackHandler: Object that manages all the callbacks available for the WebView.
    public init(
        options: OSIABWebViewOptions,
        customHeaders: [String: String]? = nil,
        cacheManager: OSIABCacheManager,
        callbackHandler: OSIABWebViewCallbackHandler
    ) {
        self.options = options
        self.customHeaders = customHeaders
        self.cacheManager = cacheManager
        self.callbackHandler = callbackHandler
    }
        
    public func handleOpen(_ url: URL, _ completionHandler: @escaping (ReturnType) -> Void) {
        if self.options.clearCache {
            self.cacheManager.clearCache()
        } else if self.options.clearSessionCache {
            self.cacheManager.clearSessionCache()
        }
        
        let viewModel = OSIABWebViewModel(
            url: url,
            customHeaders: customHeaders,
            webViewConfiguration: options.toConfigurationModel().toWebViewConfiguration(),
            scrollViewBounces: options.allowOverScroll,
            customUserAgent: options.customUserAgent,
            backForwardNavigationGestures: options.allowsBackForwardNavigationGestures,
            uiModel: options.toUIModel(),
            callbackHandler: callbackHandler
        )
        
        let dismissCallback: () -> Void = { self.callbackHandler.onBrowserClosed(true) }
        let hostingController: UIViewController
        
        if #available(iOS 14.0, *) {
            hostingController = OSIABWebViewController(rootView: .init(viewModel), dismiss: dismissCallback)
        } else {
            hostingController = OSIABWebView13Controller(rootView: .init(viewModel), dismiss: dismissCallback)
        }
        hostingController.modalPresentationStyle = options.modalPresentationStyle
        hostingController.modalTransitionStyle = options.modalTransitionStyle
        hostingController.presentationController?.delegate = self
        
        completionHandler(hostingController)
    }
}

// MARK: - Accelerator methods.
private extension OSIABWebViewOptions {
    /// Converts the current value to `OSIABWebViewConfigurationModel` equivalent.
    /// - Returns: The `OSIABWebViewConfigurationModel` equivalent value.
    func toConfigurationModel() -> OSIABWebViewConfigurationModel {
        .init(
            mediaTypesRequiringUserActionForPlayback,
            enableViewportScale,
            allowInLineMediaPlayback,
            surpressIncrementalRendering
        )
    }
    
    /// Converts the current value to `OSIABWebViewUIModel` equivalent.
    /// - Returns: The `OSIABWebViewUIModel` equivalent value.
    func toUIModel() -> OSIABWebViewUIModel {
        .init(
            showURL: showURL,
            showToolbar: showToolbar,
            toolbarPosition: toolbarPosition,
            showNavigationButtons: showNavigationButtons,
            leftToRight: leftToRight,
            closeButtonText: closeButtonText
        )
    }
}

// MARK: - UIAdaptivePresentationControllerDelegate implementation
extension OSIABWebViewRouterAdapter: UIAdaptivePresentationControllerDelegate {
    public func presentationControllerDidDismiss(_ presentationController: UIPresentationController) {
        self.callbackHandler.onBrowserClosed(true)
    }
}

/// A subclass for `UIHostingController` where it's possible to delegate the `dismiss` call to its callers.
@available(iOS 14.0, *)
private class OSIABWebViewController: UIHostingController<OSIABWebViewWrapperView> {
    /// Callback to trigger when the view controller is closed.
    let dismiss: (() -> Void)?
    
    /// Constructor method.
    /// - Parameters:
    ///   - rootView: The root view of the SwiftUI view hierarchy that you want to manage using the hosting view controller.
    ///   - dismiss: The callback to trigger when the view controller is dismissed.
    init(rootView: OSIABWebViewWrapperView, dismiss: (() -> Void)?) {
        self.dismiss = dismiss
        super.init(rootView: rootView)
    }
    
    @MainActor required dynamic init?(coder aDecoder: NSCoder) {
        self.dismiss = nil
        super.init(coder: aDecoder)
    }
    
    override func dismiss(animated flag: Bool, completion: (() -> Void)? = nil) {
        guard presentedViewController == nil else {
            return super.dismiss(animated: flag, completion: completion)
        }
        
        super.dismiss(animated: flag) {
            self.dismiss?()
            completion?()
        }
    }
}

/// A subclass for `UIHostingController` where it's possible to delegate the `dismiss` call to its callers.
@available(iOS, deprecated: 14.0, message: "Use OSIABWebViewController for iOS 14.0+")
private class OSIABWebView13Controller: UIHostingController<OSIABWebView13WrapperView> {
    /// Callback to trigger when the view controller is closed.
    let dismiss: (() -> Void)?
    
    /// Constructor method.
    /// - Parameters:
    ///   - rootView: The root view of the SwiftUI view hierarchy that you want to manage using the hosting view controller.
    ///   - dismiss: The callback to trigger when the view controller is dismissed.
    init(rootView: OSIABWebView13WrapperView, dismiss: (() -> Void)?) {
        self.dismiss = dismiss
        super.init(rootView: rootView)
    }
    
    @MainActor required dynamic init?(coder aDecoder: NSCoder) {
        self.dismiss = nil
        super.init(coder: aDecoder)
    }
    
    override func dismiss(animated flag: Bool, completion: (() -> Void)? = nil) {
        super.dismiss(animated: flag, completion: {
            self.dismiss?()
            completion?()
        })
    }
}
