import Combine
@preconcurrency import WebKit

/// View Model containing all the WebView's customisations.
class OSIABWebViewModel: NSObject, ObservableObject {
    /// The WebView to display and configure.
    let webView: WKWebView
    /// Sets the text to display on the Close button.
    let closeButtonText: String
    /// Object that manages all the callbacks available for the WebView.
    private let callbackHandler: OSIABWebViewCallbackHandler
    
    /// Sets the position to display the Toolbar.
    let toolbarPosition: OSIABToolbarPosition?
    /// Indicates if the navigations should be displayed on the toolbar.
    let showNavigationButtons: Bool
    /// Indicates the positions of the navigation buttons and the close button - which one is on the left and on the right.
    let leftToRight: Bool
    
    /// Indicates if first load is already done. This is important in order to trigger the `browserPageLoad` event.
    private var firstLoadDone: Bool = false
    
    /// Custom headers to be used by the WebView.
    private let customHeaders: [String: String]?
    
    /// The current URL being displayed
    @Published private(set) var url: URL
    /// Indicates if the URL is being loaded into the screen.
    @Published private(set) var isLoading: Bool = true
    /// Indicates if there was any error while loading the URL.
    @Published private(set) var error: Error?
    /// Indicates if the back button is available for pressing.
    @Published private(set) var backButtonEnabled: Bool = true
    /// Indicates if the forward button is available for pressing.
    @Published private(set) var forwardButtonEnabled: Bool = true
    
    /// The current address label being displayed on the screen. Empty string indicates that the address will not be displayed.
    @Published private(set) var addressLabel: String = ""
    
    private var cancellables = Set<AnyCancellable>()
    
    /// Constructor method.
    /// - Parameters:
    ///   - url: The current URL being displayed
    ///   - webView: The WebView to display and configure.
    ///   - scrollViewBounces: Indicates if the WebView's bounce property should be enabled. Defaults to `true`.
    ///   - customUserAgent: Sets a custom user agent for the WebView.
    ///   - uiModel: Collection of properties to apply to the WebView's interface.
    ///   - callbackHandler: Object that manages all the callbacks available for the WebView.
    init(
        url: URL,
        customHeaders: [String: String]? = nil,
        webView: WKWebView,
        scrollViewBounces: Bool = true,
        customUserAgent: String? = nil,
        backForwardNavigationGestures: Bool = true,
        uiModel: OSIABWebViewUIModel,
        callbackHandler: OSIABWebViewCallbackHandler
    ) {
        self.url = url
        self.customHeaders = customHeaders
        self.webView = webView
        self.closeButtonText = uiModel.closeButtonText
        self.callbackHandler = callbackHandler
        self.toolbarPosition = uiModel.showToolbar ? uiModel.toolbarPosition : nil
        if uiModel.showToolbar && uiModel.showURL {
            self.addressLabel = url.absoluteString
        }
        self.showNavigationButtons = uiModel.showNavigationButtons
        self.leftToRight = uiModel.leftToRight
        super.init()
        self.webView.allowsBackForwardNavigationGestures = backForwardNavigationGestures
        self.webView.scrollView.bounces = scrollViewBounces
        self.webView.customUserAgent = customUserAgent
        self.webView.navigationDelegate = self
        self.webView.uiDelegate = self
#if DEBUG
        if #available(iOS 16.4, *) {
            self.webView.isInspectable = true
        }
#endif
        self.setupBindings(uiModel.showURL, uiModel.showToolbar, uiModel.showNavigationButtons)
    }
    
    /// Constructor method.
    /// - Parameters:
    ///   - url: The current URL being displayed
    ///   - webViewConfiguration: Collection of properties with which to initialize the WebView.
    ///   - scrollViewBounces: Indicates if the WebView's bounce property should be enabled. Defaults to `true`.
    ///   - customUserAgent: Sets a custom user agent for the WebView.
    ///   - uiModel: Collection of properties to apply to the WebView's interface.
    ///   - callbackHandler: Object that manages all the callbacks available for the WebView.
    convenience init(
        url: URL,
        customHeaders: [String: String]? = nil,
        webViewConfiguration: WKWebViewConfiguration,
        scrollViewBounces: Bool = true,
        customUserAgent: String? = nil,
        backForwardNavigationGestures: Bool = true,
        uiModel: OSIABWebViewUIModel,
        callbackHandler: OSIABWebViewCallbackHandler
    ) {
        self.init(
            url: url,
            customHeaders: customHeaders,
            webView: WKWebView(frame: .zero, configuration: webViewConfiguration),
            scrollViewBounces: scrollViewBounces,
            customUserAgent: customUserAgent,
            backForwardNavigationGestures: backForwardNavigationGestures,
            uiModel: uiModel,
            callbackHandler: callbackHandler
        )
    }
            
    /// Setups the combine bindings, so that the Published properties can be filled automatically and reactively.
    private func setupBindings(_ showURL: Bool, _ showToolbar: Bool, _ showNavigationButtons: Bool) {
        if #available(iOS 14.0, *) {
            webView.publisher(for: \.isLoading)
                .assign(to: &$isLoading)
            webView.publisher(for: \.url)
                .compactMap { $0 }
                .assign(to: &$url)
            if showToolbar {
                if showNavigationButtons {
                    webView.publisher(for: \.canGoBack)
                        .assign(to: &$backButtonEnabled)
                    
                    webView.publisher(for: \.canGoForward)
                        .assign(to: &$forwardButtonEnabled)
                }
                if showURL {
                    $url.map(\.absoluteString)
                        .assign(to: &$addressLabel)
                }
            }
        } else {
            webView.publisher(for: \.isLoading)
                .assign(to: \.isLoading, on: self)
                .store(in: &cancellables)
            webView.publisher(for: \.url)
                .compactMap { $0 }
                .assign(to: \.url, on: self)
                .store(in: &cancellables)
            if showToolbar {
                if showNavigationButtons {
                    webView.publisher(for: \.canGoBack)
                        .assign(to: \.backButtonEnabled, on: self)
                        .store(in: &cancellables)
                    webView.publisher(for: \.canGoForward)
                        .assign(to: \.forwardButtonEnabled, on: self)
                        .store(in: &cancellables)
                }
                if showURL {
                    $url.map(\.absoluteString)
                        .assign(to: \.addressLabel, on: self)
                        .store(in: &cancellables)
                }
            }
        }
    }
    
    /// MitSpor-rettelse: sat, når første indlæsning er bestilt.
    private var initialLoadRequested = false

    /// MitSpor-rettelse: indlæser kun siden første gang visningen vises.
    /// SwiftUI kalder onAppear igen, når en fuldskærmsvisning ovenpå (fx
    /// kameraet eller billedvælgeren fra et <input type="file">) lukkes, og
    /// da `url` følger den aktuelle side, blev den så genindlæst, så åbne
    /// dialoger og igangværende uploads gik tabt.
    func loadInitialURL() {
        guard !initialLoadRequested else { return }
        initialLoadRequested = true
        loadURL()
    }

    /// Loads the URL within the WebView. Is the first operation to be performed when the view is displayed.
    func loadURL() {
        var request = URLRequest(url: url)
        customHeaders?.forEach { key, value in
            request.setValue(value, forHTTPHeaderField: key)
        }
        webView.load(request)
    }
    
    /// Signals the WebView to move forward. This is performed as a reaction to a button click.
    func forwardButtonPressed() {
        webView.goForward()
    }
    
    /// Signals the WebView to move backwards. This is performed as a reaction to a button click.
    func backButtonPressed() {
        webView.goBack()
    }
    
    /// Signals the WebView to be closed, triggering the `browserClosed` event. This is performed as a reaction to a button click.
    func closeButtonPressed() {
        callbackHandler.onBrowserClosed(false)
    }
}

// MARK: - WKNavigationDelegate implementation
extension OSIABWebViewModel: WKNavigationDelegate {
    
    func webView(_ webView: WKWebView, decidePolicyFor navigationAction: WKNavigationAction, decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
        guard let url = navigationAction.request.url else { return decisionHandler(.cancel) }
        
        // if is an app store, tel, sms, mailto or geo link, let the system handle it, otherwise it fails to load it
        if ["itms-appss", "itms-apps", "tel", "sms", "mailto", "geo"].contains(url.scheme) {
            webView.stopLoading()
            callbackHandler.onDelegateURL(url)
            decisionHandler(.cancel)
            return
        }
        
        if navigationAction.targetFrame != nil {
            decisionHandler(.allow)
        } else {
            webView.load(navigationAction.request)
            decisionHandler(.cancel)
        }
    }
    
    func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
        if !firstLoadDone {
            callbackHandler.onBrowserPageLoad()
            firstLoadDone = true
        } else {
            callbackHandler.onBrowserPageNavigationCompleted(url.absoluteString)
        }
        error = nil
    }
    
    func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: Error) {
        handleWebViewNavigationError("didFailNavigation", error: error)
    }
    
    func webView(_ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: Error) {
        handleWebViewNavigationError("didFailProvisionalNavigation", error: error)
    }
    
    private func handleWebViewNavigationError(_ delegateName: String, error: Error) {
        print("webView: \(delegateName) - \(error.localizedDescription)")
        if (error as NSError).code != NSURLErrorCancelled {
            self.error = error
        }
    }
}

// MARK: - WKUIDelegate implementation
extension OSIABWebViewModel: WKUIDelegate {
    typealias ButtonHandler = (UIAlertController) -> Void
    
    /// Creates an `UIAlertController` instance with the passed information.
    /// - Parameters:
    ///   - message: Message to be displayed in the alert.
    ///   - okButtonHandler: Handler for the ok button click operation.
    ///   - cancelButtonHandler: Handler for the cancel button click operation. It's optional as this button is not always present.
    /// - Returns: The created `UIAlertController` instance.
    private func createAlertController(withBodyText message: String, okButtonHandler: @escaping ButtonHandler, cancelButtonHandler: ButtonHandler? = nil) -> UIAlertController {
        let title = Bundle.main.infoDictionary?[kCFBundleNameKey as String] as? String ?? ""
        let alert = UIAlertController(title: title, message: message, preferredStyle: .alert)
        let okAction = UIAlertAction(title: "OK", style: .default) { _ in
            okButtonHandler(alert)
        }
        alert.addAction(okAction)
        
        if let cancelButtonHandler {
            let cancelAction = UIAlertAction(title: "Cancel", style: .cancel) { _ in
                cancelButtonHandler(alert)
            }
            alert.addAction(cancelAction)
        }
        
        return alert
    }
    
    func webView(_ webView: WKWebView, createWebViewWith configuration: WKWebViewConfiguration, for navigationAction: WKNavigationAction, windowFeatures: WKWindowFeatures) -> WKWebView? {
        if navigationAction.targetFrame == nil, let url = navigationAction.request.url {
            webView.load(URLRequest(url: url))
        }
        return nil
    }

    
    func webView(_ webView: WKWebView, runJavaScriptAlertPanelWithMessage message: String, initiatedByFrame frame: WKFrameInfo, completionHandler: @escaping () -> Void) {
        let result = createAlertController(
            withBodyText: message,
            okButtonHandler: { alert in
                completionHandler()
                alert.dismiss(animated: true)
            }
        )
        callbackHandler.onDelegateAlertController(result)
    }
    
    func webView(_ webView: WKWebView, runJavaScriptConfirmPanelWithMessage message: String, initiatedByFrame frame: WKFrameInfo, completionHandler: @escaping (Bool) -> Void) {
        let handler: (UIAlertController, Bool) -> Void = { alert, input in
            completionHandler(input)
            alert.dismiss(animated: true)
        }
        let result = createAlertController(
            withBodyText: message,
            okButtonHandler: { handler($0, true) },
            cancelButtonHandler: { handler($0, false) }
        )
        callbackHandler.onDelegateAlertController(result)
    }
    
    func webView(_ webView: WKWebView, runJavaScriptTextInputPanelWithPrompt prompt: String, defaultText: String?, initiatedByFrame frame: WKFrameInfo, completionHandler: @escaping (String?) -> Void) {
        let handler: (UIAlertController, Bool) -> Void = { alert, returnTextField in
            completionHandler(returnTextField ? alert.textFields?.first?.text : nil)
            alert.dismiss(animated: true)
        }
        let result = createAlertController(
            withBodyText: prompt,
            okButtonHandler: { handler($0, true) },
            cancelButtonHandler: { handler($0, false) }
        )
        result.addTextField { $0.text = defaultText }
        callbackHandler.onDelegateAlertController(result)
    }
}
