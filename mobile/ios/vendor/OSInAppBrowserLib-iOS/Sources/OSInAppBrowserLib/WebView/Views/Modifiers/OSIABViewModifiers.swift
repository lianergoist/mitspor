import SwiftUI

/// Object that controls the layout direction the view will assume
enum OSIABLayoutDirectionEnum {
    case fixed(value: LayoutDirection)  // assumes the value passed
    case leftToRightBasedOn(value: Bool)    // 'leftToRight' or the opposite is set based on the boolean passed
}

/// A layout direction modifier applied to a view, based on an `OSIABLayoutDirectionEnum` object.
struct OSIABLayoutDirectionModifier: ViewModifier {
    /// The direction logic to use to set the modifier.
    private let directionType: OSIABLayoutDirectionEnum
    
    /// Constructor method.
    /// - Parameter directionType: The direction logic to use to set the modifier.
    init(_ directionType: OSIABLayoutDirectionEnum) {
        self.directionType = directionType
    }
    
    func body(content: Content) -> some View {
        let layoutDirection =
        switch self.directionType {
        case .fixed(let value): value
        case .leftToRightBasedOn(let value): value ? LayoutDirection.leftToRight : .rightToLeft
        }
        
        return content.environment(\.layoutDirection, layoutDirection)
    }
}

extension View {
    /// Applies the layout direction modifier to the view.
    /// - Parameter value: The direction logic to use to set the modifier.
    /// - Returns: The result of applying the modifier
    func layoutDirection(_ value: OSIABLayoutDirectionEnum) -> some View {
        modifier(OSIABLayoutDirectionModifier(value))
    }
}
