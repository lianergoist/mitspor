import WebKit

/// Structure that holds the value that a WebView (especifically `OSIABWebViewRouterAdapter`) can use for its visual presentation.
public class OSIABWebViewOptions: OSIABOptions {
    /// Indicates if the URL should be displayed.
    let showURL: Bool
    /// Indicates if the toolbar should be displayed.
    let showToolbar: Bool
    /// Indicates if the browser's cookie cache should be cleared before the new window is opened.
    let clearCache: Bool
    /// Indicates if the session cookie cache should be cleared before the new window is opened.
    let clearSessionCache: Bool
    /// Indicates if HTML5 audio or video should be prevented from being autoplayed.
    private let mediaPlaybackRequiresUserAction: Bool
    /// Sets the text to display on the Close button.
    let closeButtonText: String
    /// Sets the position to display the Toolbar.
    let toolbarPosition: OSIABToolbarPosition
    /// Indicates if the navigations should be displayed on the toolbar.
    let showNavigationButtons: Bool
    /// Indicates the positions of the navigation buttons and the close button - which one is on the left and on the right.
    let leftToRight: Bool
    /// Indicates if the WebView's bounce property should be enabled.
    let allowOverScroll: Bool
    /// Indicates if scaling through a meta tag should be prevented.
    let enableViewportScale: Bool
    /// Indicates if in-line HTML5 media playback should be enabled.
    let allowInLineMediaPlayback: Bool
    /// Indicates if the rendering should wait until all new view content is received.
    let surpressIncrementalRendering: Bool
    /// Sets a custom user agent for the WebView.
    let customUserAgent: String?
    /// Indicates if swipe gestures for navigating backward and forward in the WebView history are enabled.
    let allowsBackForwardNavigationGestures: Bool
    
    /// Constructor method.
    /// - Parameters:
    ///   - showURL: Indicates if the URL should be displayed. `true` is provided in case of no value.
    ///   - showToolbar: Indicates if the toolbar should be displayed. `true` is provided in case of no value.
    ///   - clearCache: Indicates if the browser's cookie cache should be cleared before the new window is opened. `true` is provided in case of no value.
    ///   - clearSessionCache: Indicates if the session cookie cache should be cleared before the new window is opened. `true` is provided in case of no value.
    ///   - mediaPlaybackRequiresUserAction: Indicates if HTML5 audio or video should be prevented from being autoplayed. `false` is provided in case of no value.
    ///   - closeButtonText: Sets the text to display on the Close button. `Close` is provided in case of no value or empty text.
    ///   - toolbarPosition: Sets the position to display the Toolbar. `defaultValue` is provided in case of no value.
    ///   - showNavigationButtons: Indicates if the navigations should be displayed on the toolbar. `true` is provided in case of no value.
    ///   - leftToRight: Indicates the positions of the navigation buttons and the close button - which one is on the left and on the right. `false` is provided in case of no value.
    ///   - allowOverScroll: Indicates if the WebView's bounce property should be enabled. `true` is provided in case of no value.
    ///   - enableViewportScale: Indicates if scaling through a meta tag should be prevented. `false` is provided in case of no value.
    ///   - allowInLineMediaPlayback: Indicates if in-line HTML5 media playback should be enabled. `false` is provided in case of no value.
    ///   - surpressIncrementalRendering: Indicates if the rendering should wait until all new view content is received. `false` is provided in case of no value.
    ///   - viewStyle: The view style to present. `defaultValue` is provided in case of no value.
    ///   - animationEffect: The animation effect for the presentation appearance and dismissal. `defaultValue` is provided in case of no value.
    ///   - customUserAgent: Sets a custom user agent for the WebView.`
    ///   - allowsBackForwardNavigationGestures: Indicates if swipe gestures for navigating backward and forward in the WebView history are enabled.
    public init(
        showURL: Bool = true,
        showToolbar: Bool = true,
        clearCache: Bool = true,
        clearSessionCache: Bool = true,
        mediaPlaybackRequiresUserAction: Bool = false,
        closeButtonText: String = "Close",
        toolbarPosition: OSIABToolbarPosition = .defaultValue,
        showNavigationButtons: Bool = true,
        leftToRight: Bool = false,
        allowOverScroll: Bool = true,
        enableViewportScale: Bool = false,
        allowInLineMediaPlayback: Bool = false,
        surpressIncrementalRendering: Bool = false,
        viewStyle: OSIABViewStyle = .defaultValue, 
        animationEffect: OSIABAnimationEffect = .defaultValue,
        customUserAgent: String? = nil,
        allowsBackForwardNavigationGestures: Bool = true
    ) {
        self.showURL = showURL
        self.showToolbar = showToolbar
        self.clearCache = clearCache
        self.clearSessionCache = clearSessionCache
        self.mediaPlaybackRequiresUserAction = mediaPlaybackRequiresUserAction
        self.closeButtonText = closeButtonText.isEmpty ? "Close" : closeButtonText
        self.toolbarPosition = toolbarPosition
        self.showNavigationButtons = showNavigationButtons
        self.leftToRight = leftToRight
        self.allowOverScroll = allowOverScroll
        self.enableViewportScale = enableViewportScale
        self.allowInLineMediaPlayback = allowInLineMediaPlayback
        self.surpressIncrementalRendering = surpressIncrementalRendering
        self.customUserAgent = customUserAgent
        self.allowsBackForwardNavigationGestures = allowsBackForwardNavigationGestures
        super.init(viewStyle: viewStyle, animationEffect: animationEffect)
    }
}

// MARK: - WKWebView extensions
extension OSIABWebViewOptions {
    /// The `WKWebView`'s `mediaPlaybackRequiresUserAction` equivalent value.
    var mediaTypesRequiringUserActionForPlayback: WKAudiovisualMediaTypes {
        self.mediaPlaybackRequiresUserAction ? .all : []
    }
}
