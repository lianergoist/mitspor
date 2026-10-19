/// Collection of properties to apply to the WebView's interface.
struct OSIABWebViewUIModel {
    /// Indicates if the URL should be displayed.
    let showURL: Bool
    /// Indicates if the toolbar should be displayed.
    let showToolbar: Bool
    /// Sets the position to display the Toolbar.
    let toolbarPosition: OSIABToolbarPosition
    /// Indicates if the navigations should be displayed on the toolbar.
    let showNavigationButtons: Bool
    /// Indicates the positions of the navigation buttons and the close button - which one is on the left and on the right.
    let leftToRight: Bool
    /// Sets the text to display on the Close button.
    let closeButtonText: String
    
    /// Constructor method.
    /// - Parameters:
    ///   - showURL: /// Indicates if the URL should be displayed. Defaults to `true`.
    ///   - showToolbar: /// Indicates if the toolbar should be displayed. Defaults to `true`.
    ///   - toolbarPosition: /// Sets the position to display the Toolbar. Defaults to `defaultValue`.
    ///   - showNavigationButtons: /// Indicates if the navigations should be displayed on the toolbar. Defaults to `true`.
    ///   - leftToRight: /// Indicates the positions of the navigation buttons and the close button - which one is on the left and on the right. Defaults to `false`.
    ///   - closeButtonText: Sets the text to display on the Close button. Defaults to `Close`.
    init(
        showURL: Bool = true,
        showToolbar: Bool = true,
        toolbarPosition: OSIABToolbarPosition = .defaultValue,
        showNavigationButtons: Bool = true,
        leftToRight: Bool = false,
        closeButtonText: String = "Close"
    ) {
        self.showURL = showURL
        self.showToolbar = showToolbar
        self.toolbarPosition = toolbarPosition
        self.showNavigationButtons = showNavigationButtons
        self.leftToRight = leftToRight
        self.closeButtonText = closeButtonText
    }
}
