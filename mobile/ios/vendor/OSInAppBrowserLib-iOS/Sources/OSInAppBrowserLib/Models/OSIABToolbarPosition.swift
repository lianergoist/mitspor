/// Enumerator that holds all possible values for the WebView's toolbar position.
public enum OSIABToolbarPosition: String {
    case top = "TOP"
    case bottom = "BOTTOM"
    
    /// Default value to consider in the absence of value.
    public static let defaultValue: Self = .top
}
