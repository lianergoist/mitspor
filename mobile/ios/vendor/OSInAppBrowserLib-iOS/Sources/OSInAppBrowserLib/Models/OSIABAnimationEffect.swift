import UIKit

/// Enumerator that holds all possible values for the Animation Effect.
public enum OSIABAnimationEffect: String {
    case coverVertical = "COVER_VERTICAL"
    case crossDissolve = "CROSS_DISSOLVE"
    case flipHorizontal = "FLIP_HORIZONTAL"
    
    /// Default value to consider in the absence of value.
    public static let defaultValue: Self = .coverVertical
}

// MARK: - UIKit Mapping Method
extension OSIABAnimationEffect {
    /// Converts the current value to `UIKit`'s `UIModalTransitionStyle` equivalent.
    /// - Returns: `UIKit`'s `UIModalTransitionStyle` equivalent value.
    func toModalTransitionStyle() -> UIModalTransitionStyle {
        let result: UIModalTransitionStyle
        
        switch self {
        case .coverVertical: result = .coverVertical
        case .crossDissolve: result = .crossDissolve
        case .flipHorizontal: result = .flipHorizontal
        }
        
        return result
    }
}
