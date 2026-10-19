import UIKit

/// Structure that holds the common values for SystemBrowser and WebView to use for its visual presentation.
public class OSIABOptions {
    /// The view style to present.
    private let viewStyle: OSIABViewStyle
    /// The animation effect for the presentation appearance and dismissal.
    private let animationEffect: OSIABAnimationEffect
    
    /// Constructor method.
    /// - Parameters:
    ///   - viewStyle: The view style to present. `defaultValue` is provided in case of no value.
    ///   - animationEffect: The animation effect for the presentation appearance and dismissal. `defaultValue` is provided in case of no value.
    public init(viewStyle: OSIABViewStyle, animationEffect: OSIABAnimationEffect) {
        self.viewStyle = viewStyle
        self.animationEffect = animationEffect
    }
}

// MARK: - UIKit extensions
extension OSIABOptions {
    /// The `UIKit`'s `modalPresentationStyle` equivalent value.
    var modalPresentationStyle: UIModalPresentationStyle {
        self.viewStyle.toModalPresentationStyle()
    }
    /// The `UIKit`'s `modalTransitionStyle` equivalent value.
    var modalTransitionStyle: UIModalTransitionStyle {
        self.animationEffect.toModalTransitionStyle()
    }
}
