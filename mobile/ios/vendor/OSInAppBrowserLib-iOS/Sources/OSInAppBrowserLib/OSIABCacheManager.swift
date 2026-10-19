import WebKit

/// Protocol to be implemented by cache managers, to perform clear operations.
public protocol OSIABCacheManager {
    typealias CacheCompletion = () -> Void
    
    /// Clears the cookie cache.
    /// - Parameter completionHandler: Handler to be called when the operation is completed.
    func clearCache(_ completionHandler: CacheCompletion?)
    
    /// Clears the session cookie cache.
    /// - Parameter completionHandler: Handler to be called when the operation is completed.
    func clearSessionCache(_ completionHandler: CacheCompletion?)
}

/// An accelerator for the protocol, clearing the parameter need.
public extension OSIABCacheManager {
    func clearCache(_ completionHandler: CacheCompletion? = nil) {
        self.clearCache(completionHandler)
    }
    
    func clearSessionCache(_ completionHandler: CacheCompletion? = nil) {
        self.clearSessionCache(completionHandler)
    }
}

/// Browser cache manager. This is used by WebView for proper management.
public struct OSIABBrowserCacheManager {
    /// Object that manages the data used by a website.
    let dataStore: WKWebsiteDataStore
    
    /// Constructor method.
    /// - Parameter dataStore: Object that manages the data used by a website.
    public init(dataStore: WKWebsiteDataStore) {
        self.dataStore = dataStore
    }
}

extension OSIABBrowserCacheManager: OSIABCacheManager {
    /// Performs the cache clearance operation.
    /// - Parameters:
    ///   - isSessionCookie: Indicates if the cache to clear is session related only.
    ///   - completionHandler: Handler to be called when the operation is completed.
    private func clearCache(sessionCookiesOnly isSessionCookie: Bool, _ completionHandler: CacheCompletion?) {
        let cookieStore = self.dataStore.httpCookieStore
        
        func delete(_ cookieArray: [HTTPCookie], _ completionHandler: CacheCompletion?) {
            guard let cookie = cookieArray.first else {
                completionHandler?()
                return
            }
            cookieStore.delete(cookie) {
                delete(Array(cookieArray.dropFirst()), completionHandler)
            }
        }
        
        cookieStore.getAllCookies { cookies in
            delete(isSessionCookie ? cookies.filter({ $0.isSessionOnly }) : cookies, completionHandler)
        }
    }
    
    public func clearCache(_ completionHandler: CacheCompletion?) {
        self.clearCache(sessionCookiesOnly: false, completionHandler)
    }
    
    public func clearSessionCache(_ completionHandler: CacheCompletion?) {
        self.clearCache(sessionCookiesOnly: true, completionHandler)
    }
}
