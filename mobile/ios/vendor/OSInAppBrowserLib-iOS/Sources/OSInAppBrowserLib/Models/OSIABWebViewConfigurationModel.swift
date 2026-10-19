import WebKit

/// Collection of properties with which to initialize the WebView.
struct OSIABWebViewConfigurationModel {
    /// Indicates if HTML5 audio or video should be prevented from being autoplayed.
    private let mediaTypesRequiringUserActionForPlayback: WKAudiovisualMediaTypes
    /// Indicates if scaling through a meta tag should be prevented.
    private let ignoresViewportScaleLimits: Bool
    /// Indicates if in-line HTML5 media playback should be enabled.
    private let allowsInlineMediaPlayback: Bool
    /// Indicates if the rendering should wait until all new view content is received.
    private let suppressesIncrementalRendering: Bool
    
    /// Constructor method.
    /// - Parameters:
    ///   - mediaTypesRequiringUserActionForPlayback: Indicates if HTML5 audio or video should be prevented from being autoplayed. Defaults to nothing being autoplayed.
    ///   - ignoresViewportScaleLimits: Indicates if scaling through a meta tag should be prevented. Defaults to `false`.
    ///   - allowsInlineMediaPlayback: Indicates if in-line HTML5 media playback should be enabled. Defaults to `false`
    ///   - suppressesIncrementalRendering: Indicates if the rendering should wait until all new view content is received. Defaults to `false`.
    init(
        _ mediaTypesRequiringUserActionForPlayback: WKAudiovisualMediaTypes = [],
        _ ignoresViewportScaleLimits: Bool = false,
        _ allowsInlineMediaPlayback: Bool = false,
        _ suppressesIncrementalRendering: Bool = false
    ) {
        self.mediaTypesRequiringUserActionForPlayback = mediaTypesRequiringUserActionForPlayback
        self.ignoresViewportScaleLimits = ignoresViewportScaleLimits
        self.allowsInlineMediaPlayback = allowsInlineMediaPlayback
        self.suppressesIncrementalRendering = suppressesIncrementalRendering
    }
    
    /// Creates a `WKWebViewConfiguration` object with all the model's properties.
    func toWebViewConfiguration() -> WKWebViewConfiguration {
        let configuration = WKWebViewConfiguration()
        configuration.mediaTypesRequiringUserActionForPlayback = mediaTypesRequiringUserActionForPlayback
        configuration.ignoresViewportScaleLimits = ignoresViewportScaleLimits
        configuration.allowsInlineMediaPlayback = allowsInlineMediaPlayback
        configuration.suppressesIncrementalRendering = suppressesIncrementalRendering
        return configuration
    }
}
