import SwiftUI

/// View that provides the customisable "browser" experience.
struct OSIABWebView: View {
    /// View Model containing all the customisable elements.
    @ObservedObject private var model: OSIABWebViewModel

    /// Constructor method.
    /// - Parameter model: View Model containing all the customisable elements.
    init(_ model: OSIABWebViewModel) {
        self.model = model
    }

    var body: some View {
        VStack {
            if let toolbarPosition = model.toolbarPosition {
                HStack {
                    if toolbarPosition == .top {
                        OSIABNavigationView(
                            showNavigationButtons: model.showNavigationButtons,
                            backButtonPressed: model.backButtonPressed,
                            backButtonEnabled: model.backButtonEnabled,
                            forwardButtonPressed: model.forwardButtonPressed,
                            forwardButtonEnabled: model.forwardButtonEnabled,
                            addressLabel: model.addressLabel,
                            addressLabelAlignment: model.showNavigationButtons ? .center : .leading,
                            buttonLayoutDirection: .fixed(value: .leftToRight)  // we force 'back' to come always before 'forward'
                        )
                    }
                    Spacer()

                    Button(action: model.closeButtonPressed, label: {
                        Text(model.closeButtonText)
                            .bold()
                    })
                    .buttonStyle(.plain)
                }
                .padding()
            }
            if let error = model.error {
                OSIABErrorView(
                    error,
                    reload: model.loadURL,
                    reloadViewLayoutDirection: .fixed(value: .leftToRight)
                )
            } else {
                OSIABWebViewRepresentable(model.webView)
            }

            if model.toolbarPosition == .bottom {
                OSIABNavigationView(
                    showNavigationButtons: model.showNavigationButtons,
                    backButtonPressed: model.backButtonPressed,
                    backButtonEnabled: model.backButtonEnabled,
                    forwardButtonPressed: model.forwardButtonPressed,
                    forwardButtonEnabled: model.forwardButtonEnabled,
                    addressLabel: model.addressLabel,
                    addressLabelAlignment: model.showNavigationButtons ? .trailing : .center,
                    buttonLayoutDirection: .fixed(value: .leftToRight) // we force 'back' to come always before 'forward'
                )
                .padding()
            }
        }
        .onAppear(perform: {
            // MitSpor-rettelse: onAppear kører igen, når kameraet eller
            // billedvælgeren lukker, og loadURL() genindlæste så siden.
            model.loadInitialURL()
        })
        .layoutDirection(.leftToRightBasedOn(value: model.leftToRight))
    }
}

// MARK: - OSIABViewModel's constructor accelerator.
private extension OSIABWebViewModel {
    convenience init(
        url: String,
        showURL: Bool,
        showToolbar: Bool,
        toolbarPosition: OSIABToolbarPosition,
        showNavigationButtons: Bool,
        leftToRight: Bool,
        closeButtonText: String,
        onBrowserClosed: @escaping (Bool) -> Void
    ) {
        let configurationModel = OSIABWebViewConfigurationModel()
        self.init(
            url: .init(string: url)!,
            webViewConfiguration: configurationModel.toWebViewConfiguration(),
            uiModel: .init(
                showURL: showURL,
                showToolbar: showToolbar,
                toolbarPosition: toolbarPosition,
                showNavigationButtons: showNavigationButtons,
                leftToRight: leftToRight,
                closeButtonText: closeButtonText
            ),
            callbackHandler: .init(
                onDelegateURL: { _ in },
                onDelegateAlertController: { _ in },
                onBrowserPageLoad: {},
                onBrowserClosed: onBrowserClosed,
                onBrowserPageNavigationCompleted: { _ in }
            )
        )
    }
}

// MARK: - Preview Helper View
private struct OSIABTestWebView: View {
    @State private var closeButtonCount = 0
    private let closeButtonText: String
    private let showURL: Bool
    private let showToolbar: Bool
    private let toolbarPosition: OSIABToolbarPosition
    private let showNavigationButtons: Bool
    private let leftToRight: Bool
    private let isError: Bool

    init(
        closeButtonText: String = "Close",
        showURL: Bool = true,
        showToolbar: Bool = true,
        toolbarPosition: OSIABToolbarPosition = .defaultValue,
        showNavigationButtons: Bool = true,
        leftToRight: Bool = false,
        isError: Bool = false
    ) {
        self.closeButtonText = closeButtonText
        self.showURL = showURL
        self.showToolbar = showToolbar
        self.toolbarPosition = toolbarPosition
        self.showNavigationButtons = showNavigationButtons
        self.leftToRight = leftToRight
        self.isError = isError
    }

    var body: some View {
        VStack {
            OSIABWebView(
                .init(
                    url: self.isError ?  "https://outsystems" : "https://outsystems.com",
                    showURL: showURL,
                    showToolbar: showToolbar,
                    toolbarPosition: toolbarPosition,
                    showNavigationButtons: showNavigationButtons,
                    leftToRight: leftToRight,
                    closeButtonText: closeButtonText,
                    onBrowserClosed: { _ in closeButtonCount += 1 }
                )
            )
            Text("Close Button count: \(closeButtonCount)")
        }
    }
}

struct OSIABWebView_Previews: PreviewProvider {
    static var previews: some View {
        // MARK: - Default Views

        // Default - Light Mode
        OSIABTestWebView()

        // Default - Dark Mode
        OSIABTestWebView()
            .preferredColorScheme(.dark)

        // Error - Light Mode
        OSIABTestWebView(
            isError: true
        )

        // MARK: - Custom Close Button View

        // Custom Close Button Text
        OSIABTestWebView(
            closeButtonText: "Done"
        )

        // MARK: - No Toolbar View

        // No Toolbar
        OSIABTestWebView(
            showToolbar: false
        )

        // MARK: - Custom Views

        // No URL and No Navigation Buttons
        OSIABTestWebView(
            showURL: false,
            showNavigationButtons: false
        )

        // No URL, No Navigation Buttons and Left-to-Right
        OSIABTestWebView(
            showURL: false,
            showNavigationButtons: false,
            leftToRight: true
        )

        // No URL
        OSIABTestWebView(
            showURL: false
        )

        // No URL and Left-To-Right
        OSIABTestWebView(
            showURL: false,
            leftToRight: true
        )

        // No URL, Bottom Toolbar and No Navigation Buttons
        OSIABTestWebView(
            showURL: false,
            toolbarPosition: .bottom,
            showNavigationButtons: false
        )

        // No URL, Bottom Toolbar, No Navigation Buttons and Left-to-Right
        OSIABTestWebView(
            showURL: false,
            toolbarPosition: .bottom,
            showNavigationButtons: false,
            leftToRight: true
        )

        // No URL and Bottom Toolbar
        OSIABTestWebView(
            showURL: false,
            toolbarPosition: .bottom
        )

        // No URL, Bottom Toolbar and Left-to-Right
        OSIABTestWebView(
            showURL: false,
            toolbarPosition: .bottom,
            leftToRight: true
        )

        // No Navigation Buttons
        OSIABTestWebView(
            showNavigationButtons: false
        )

        // No Navigation Buttons and Left-to-Right
        OSIABTestWebView(
            showNavigationButtons: false,
            leftToRight: true
        )

        // Left-to-Right
        OSIABTestWebView(
            leftToRight: true
        )

        // Bottom Toolbar and No Navigation Buttons
        OSIABTestWebView(
            toolbarPosition: .bottom,
            showNavigationButtons: false
        )

        // Bottom Toolbar, No Navigation Buttons and Left-to-Right
        OSIABTestWebView(
            toolbarPosition: .bottom,
            showNavigationButtons: false,
            leftToRight: true
        )

        // Bottom Toolbar
        OSIABTestWebView(
            toolbarPosition: .bottom
        )

        // Bottom Toolbar and Left-to-Right
        OSIABTestWebView(
            toolbarPosition: .bottom,
            leftToRight: true
        )
    }
}
