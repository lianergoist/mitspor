import UIKit

/// Enumerator that holds all possible values for the View Style.
public enum OSIABViewStyle: String {
    case formSheet = "FORM_SHEET"
    case fullScreen = "FULL_SCREEN"
    case pageSheet = "PAGE_SHEET"
    
    /// Default value to consider in the absence of value.
    public static let defaultValue: Self = .fullScreen
}

// MARK: - UIKit Mapping Method
extension OSIABViewStyle {
    /// Converts the current value to `UIKit`'s `UIModalPresentationStyle` equivalent.
    /// - Returns: `UIKit`'s `UIModalPresentationStyle` equivalent value.
    func toModalPresentationStyle() -> UIModalPresentationStyle {
        let result: UIModalPresentationStyle
        
        switch self {
        case .formSheet: result = .formSheet
        case .fullScreen: result = .fullScreen
        case .pageSheet: result = .pageSheet
        }
        
        return result
    }
}
