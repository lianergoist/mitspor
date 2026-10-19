# OSInAppBrowserLib (kopi med MitSpor-rettelse)

Kopi af [OSInAppBrowserLib-iOS](https://github.com/OutSystems/OSInAppBrowserLib-iOS)
version 2.3.2 (commit dba78d3), MIT-licens (se `LICENSE`). Bruges af
`@capacitor/inappbrowser` i stedet for GitHub-pakken; `mobile/scripts/patch-inappbrowser.mjs`
peger pluginets `Package.swift` hertil (køres efter `npm install`/`npm ci`).

## Rettelse

`OSIABWebView` kaldte `model.loadURL()` i `.onAppear`. Når kameraet eller
billedvælgeren fra et `<input type="file">` lukkes, kører `onAppear` igen, og
da `model.url` følger den aktuelle side, blev siden genindlæst: åbne dialoger
forsvandt, og uploads blev afbrudt. Nu kaldes `loadInitialURL()`, som kun
indlæser første gang. Søg efter "MitSpor-rettelse" for ændringerne.

Testmålet (`Tests/`) er ikke med.

## Ved opgradering af @capacitor/inappbrowser

Tjek, hvilken version af OSInAppBrowserLib den nye udgave kræver, og om fejlen
er rettet der. Er den rettet, så fjern denne mappe og scriptet. Ellers kopiér
den nye version hertil, og læg rettelsen ind igen.
