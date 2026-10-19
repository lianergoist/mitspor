import SafariServices

/// Enumerator that holds all possible values for `SafariViewController`'s Dismiss Style.
public enum OSIABDismissStyle: String {
    case cancel = "CANCEL"
    case close = "CLOSE"
    case done = "DONE"
    
    /// Default value to consider in the absence of value.
    public static let defaultValue: Self = .done
}

// MARK: - SFSafariViewController Mapping Method
extension OSIABDismissStyle {
    /// Converts the current value to `SFSafariViewController`'s `DismissButtonStyle` equivalent.
    /// - Returns: `SFSafariViewController`'s `DismissButtonStyle` equivalent value.
    func toSFSafariViewControllerDismissButtonStyle() -> SFSafariViewController.DismissButtonStyle {
        let result: SFSafariViewController.DismissButtonStyle
        
        switch self {
        case .cancel: result = .cancel
        case .close: result = .close
        case .done: result = .done
        }
        
        return result
    }
}
