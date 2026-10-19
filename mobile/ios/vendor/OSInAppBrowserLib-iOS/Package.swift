// swift-tools-version:5.7
import PackageDescription

// Kopi af OSInAppBrowserLib 2.3.2 med MitSpor-rettelse — se README.md.
let package = Package(
    name: "OSInAppBrowserLib",
    platforms: [
        .iOS(.v14)
    ],
    products: [
        .library(
            name: "OSInAppBrowserLib",
            targets: ["OSInAppBrowserLib"]
        ),
    ],
    targets: [
        .target(
            name: "OSInAppBrowserLib",
            path: "Sources/OSInAppBrowserLib"
        ),
    ]
)
