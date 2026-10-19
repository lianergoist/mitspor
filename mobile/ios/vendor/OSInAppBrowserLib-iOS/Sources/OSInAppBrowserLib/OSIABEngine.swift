import UIKit

/// Structure responsible for managing all InAppBrowser interactions.
public struct OSIABEngine<ExternalBrowser: OSIABRouter, SystemBrowser: OSIABRouter, WebView: OSIABRouter>
where ExternalBrowser.ReturnType == Bool, SystemBrowser.ReturnType == UIViewController, WebView.ReturnType == UIViewController {
    /// Constructor method.
    public init() {
        // Empty constructor
        // This is required for the library's callers.
    }
    
    /// Delegates opening the passed `url`to the External Browser.
    /// - Parameter url: URL to be opened.
    /// - Parameter routerDelegate: The External Browser that will open the url.`
    /// - Parameter completionHandler: The callback with the result of opening the url using the External Browser.
    public func openExternalBrowser(_ url: URL, routerDelegate: ExternalBrowser, _ completionHandler: @escaping (ExternalBrowser.ReturnType) -> Void) {
        routerDelegate.handleOpen(url, completionHandler)
    }
    
    /// Delegates opening the passed `url`to the System Browser.
    /// - Parameter url: URL to be opened.
    /// - Parameter routerDelegate: The System Browser that will open the url.`
    /// - Parameter completionHandler: The callback with the result of opening the url using the System Browser.
    public func openSystemBrowser(_ url: URL, routerDelegate: SystemBrowser, _ completionHandler: @escaping (SystemBrowser.ReturnType) -> Void) {
        routerDelegate.handleOpen(url, completionHandler)
    }
    
    /// Delegates opening the passed `url` to the Web View.
    /// - Parameters:
    ///   - url: URL to be opened.
    ///   - routerDelegate: The Web View that will open the url.
    ///   - completionHandler: The callback with the result of opening the url using the Web View.
    public func openWebView(_ url: URL, routerDelegate: WebView, _ completionHandler: @escaping (WebView.ReturnType) -> Void) {
        routerDelegate.handleOpen(url, completionHandler)
    }
}
