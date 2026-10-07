# MitSpor-appen (Capacitor)

iOS- og Android-app til mitspor.dk. App-id: `dk.mitspor.app`.

## Opbygning

- `www/` er appens **lokale** sider (virker uden net). Startsiden åbner
  mitspor.dk i en separat in-app WebView (`@capacitor/inappbrowser`).
  Websiden har derfor ikke adgang til appens native funktioner.
- `www/config.js`: hvilket site appen åbner (`siteUrl`).
- `www/vendor/` genereres fra `node_modules` af `npm run vendor` og er ikke i git.
- `android/` og `ios/` er de native projekter (genereret af Capacitor, i git).
  iOS bruger Swift Package Manager (ingen CocoaPods).

Ændringer på mitspor.dk kræver intet nyt app-build. Ændringer i `www/`,
plugins eller native kode kræver et nyt build.

## Kommandoer (i mappen `mobile/`)

```bash
npm install      # første gang / efter ændringer i package.json
npm run sync     # kopiér vendor-filer og www/ ind i android/ og ios/
```

Kør `npm run sync` efter hver ændring i `www/` eller plugins, før der bygges.

## Bygning

Både Android og iOS bygges i cloud (fx Codemagic), ikke på serveren.
Signeringsnøgler og certifikater må aldrig lægges i git.

## App-ikon og opstartsskærm

Kilden er `assets/ikon.svg` (S-formet spor på MitSpor-navy `#0e3a5b`).
Ud fra den er PNG'erne i `assets/` lavet: `icon-only.png` (iOS, motiv i
skala 0.88), `icon-foreground.png` (Android, gennemsigtig, skala 0.9),
`icon-background.png` (navy) og `splash.png`/`splash-dark.png`
(2732×2732, skala 0.36). Alle størrelser til Android og iOS laves med:

```bash
npm run assets
```

Værktøjet omformaterer `android/app/src/main/AndroidManifest.xml` uden at
ændre indholdet; den ændring kan rulles tilbage med `git checkout`.
