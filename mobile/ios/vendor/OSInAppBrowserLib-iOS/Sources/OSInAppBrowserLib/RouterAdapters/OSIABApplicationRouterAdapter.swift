import UIKit

/// Adapter that makes the required calls so that can perform the External Browser routing.
public class OSIABApplicationRouterAdapter: OSIABRouter {
    public typealias ReturnType = Bool
        
    /// Constructor method.
    public init() {}
    
    public func handleOpen(_ url: URL, _ completionHandler: @escaping (ReturnType) -> Void) {
        guard UIApplication.shared.canOpenURL(url) else { return completionHandler(false) }
        UIApplication.shared.open(url, completionHandler: completionHandler)
    }
}
