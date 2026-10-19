import SwiftUI

private struct OSIABNavigationButton: View {
    /// Handler to trigger when the button is pressed.
    private let buttonPressed: () -> Void
    /// The icon to set the button with.
    private let iconName: String
    /// Indicates if the button should appeared as enabled or not.
    private let isDisabled: Bool
    
    /// Constructor method.
    /// - Parameters:
    ///   - buttonPressed: Handler to trigger when the button is pressed.
    ///   - iconName: The icon to set the button with.
    ///   - isDisabled: Indicates if the button should appeared as enabled or not.
    init(_ buttonPressed: @escaping () -> Void, iconName: String, isDisabled: Bool) {
        self.buttonPressed = buttonPressed
        self.iconName = iconName
        self.isDisabled = isDisabled
    }
    
    var body: some View {
        Button(action: buttonPressed, label: {
            Image(systemName: iconName)
        })
        .buttonStyle(.plain)
        .disabled(isDisabled)
    }
}

/// View related with displaying the Navigation items (buttons + URL address)
struct OSIABNavigationView: View {
    /// Indicates if the navigations should be displayed on the toolbar.
    private let showNavigationButtons: Bool
    /// Handler to trigger when the back button is pressed.
    private let backButtonPressed: () -> Void
    /// Indicates if the back button is available for pressing.
    private let backButtonEnabled: Bool
    /// Handler to trigger when the forward button is pressed.
    private let forwardButtonPressed: () -> Void
    /// Indicates if the forward button is available for pressing.
    private let forwardButtonEnabled: Bool
    /// The current adress label being displayed on the screen. Empty string indicates that the address will not be displayed.
    private let addressLabel: String
    /// Alignment to apply to the URL address.
    private let addressLabelAlignment: Alignment
    /// Indicates if the navigation buttons should stay on the order defined or adapt.
    private let buttonLayoutDirection: OSIABLayoutDirectionEnum
    
    /// Constructor method.
    /// - Parameters:
    ///   - showNavigationButtons: Indicates if the navigations should be displayed on the toolbar.
    ///   - backButtonPressed: Handler to trigger when the back button is pressed.
    ///   - backButtonEnabled: Indicates if the back button is available for pressing.
    ///   - forwardButtonPressed: Handler to trigger when the forward button is pressed.
    ///   - forwardButtonEnabled: Indicates if the forward button is available for pressing.
    ///   - addressLabel: The current adress label being displayed on the screen. Empty string indicates that the address will not be displayed.
    ///   - addressLabelAlignment: Alignment to apply to the URL address.
    init(
        showNavigationButtons: Bool,
        backButtonPressed: @escaping () -> Void,
        backButtonEnabled: Bool,
        forwardButtonPressed: @escaping () -> Void,
        forwardButtonEnabled: Bool,
        addressLabel: String,
        addressLabelAlignment: Alignment,
        buttonLayoutDirection: OSIABLayoutDirectionEnum
    ) {
        self.showNavigationButtons = showNavigationButtons
        self.backButtonPressed = backButtonPressed
        self.backButtonEnabled = backButtonEnabled
        self.forwardButtonPressed = forwardButtonPressed
        self.forwardButtonEnabled = forwardButtonEnabled
        self.addressLabel = addressLabel
        self.addressLabelAlignment = addressLabelAlignment
        self.buttonLayoutDirection = buttonLayoutDirection
    }
    
    var body: some View {
        HStack {
            if showNavigationButtons {
                HStack {
                    OSIABNavigationButton(
                        backButtonPressed,
                        iconName: "chevron.backward",
                        isDisabled: !backButtonEnabled
                    )
                    
                    OSIABNavigationButton(
                        forwardButtonPressed,
                        iconName: "chevron.forward", 
                        isDisabled: !forwardButtonEnabled
                    )
                }
                .layoutDirection(buttonLayoutDirection)
                
                Spacer()
            }
            
            if !addressLabel.isEmpty {
                Text(addressLabel)
                    .lineLimit(1)
                    .allowsHitTesting(false)
                    .frame(maxWidth: .infinity, alignment: addressLabelAlignment)
            }
        }
    }
}

// MARK: - Preview Helper View
private struct OSIABTestNavigationView: View {
    @State private var buttonPressedText = "No Button Pressed."
    private let showNavigationButtons: Bool
    private let backButtonEnabled: Bool
    private let forwardButtonEnabled: Bool
    private let addressLabel: String = "URL Address"

    init(
        showNavigationButtons: Bool = true,
        backButtonEnabled: Bool = false,
        forwardButtonEnabled: Bool = false
    ) {
        self.showNavigationButtons = showNavigationButtons
        self.backButtonEnabled = backButtonEnabled
        self.forwardButtonEnabled = forwardButtonEnabled
    }
    
    var body: some View {
        VStack {
            OSIABNavigationView(
                showNavigationButtons: showNavigationButtons,
                backButtonPressed: {
                    buttonPressedText = "Back Button Pressed."
                },
                backButtonEnabled: backButtonEnabled,
                forwardButtonPressed: {
                    buttonPressedText = "Forward Button Pressed."
                },
                forwardButtonEnabled: forwardButtonEnabled,
                addressLabel: addressLabel,
                addressLabelAlignment: showNavigationButtons ? .trailing : .center,
                buttonLayoutDirection: .fixed(value: .leftToRight)
            )
            .padding()
            .background(Color.secondary.opacity(0.3))
            
            Spacer()
            Text(buttonPressedText)
            Spacer()
        }
    }
}

struct OSIABNavigationView_Previews: PreviewProvider {
    static var previews: some View {
        // Default - Light Mode
        OSIABTestNavigationView()

        // Default - Dark Mode
        OSIABTestNavigationView()
            .preferredColorScheme(.dark)

        // Show Navigation Buttons Disabled
        OSIABTestNavigationView(showNavigationButtons: false)

        // Back Button Enabled
        OSIABTestNavigationView(backButtonEnabled: true)

        // Forward Button Enabled
        OSIABTestNavigationView(forwardButtonEnabled: true)

        // Both Buttons Enabled
        OSIABTestNavigationView(backButtonEnabled: true, forwardButtonEnabled: true)
    }
}
