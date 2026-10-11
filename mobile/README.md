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

## Optageren

Startsidens "Optag tur" åbner GPS-optageren (`www/optager.js`, `www/lager.js`,
`www/gpx.js`). Den bruger `@capgo/background-geolocation` (gratis, MPL-2.0),
der på Android kører som foreground service med synlig notifikation. Der
kræves ikke tilladelse til placering "hele tiden".

- **Smart-mode:** optagelsen venter, til GPS'en har haft en nøjagtighed på
  15 m eller bedre tre gange i træk (også efter en pause). Derefter gemmes
  alle punkter med nøjagtighed på 20 m eller bedre og mindst 3 m imellem
  (spring over 250 km/t kasseres). GPX-filen laves af et renset spor
  (`www/spor.js`): position og højde udglattes med et Kalman-filter
  (frem og tilbage), og Douglas-Peucker fjerner punkter på lige strækninger
  (tolerance 3 m, dog mindst ét punkt pr. 50 m). Afstand og antal punkter
  på skærmen er for det rensede spor.
- **Lagring:** appens private mappe, `optagelser/<id>/`. Hvert punkt føjes
  straks til `punkter.jsonl`, og GPX-filen skrives mindst hvert minut og ved
  stop. De rå punkter beholdes, så GPX-filen kan laves igen med andre
  indstillinger. `<id>` er en UUID og bruges senere som `client_upload_id`.
- **Afbrudt tur:** stopper appen midt i en optagelse, tilbydes den at blive
  fortsat eller afsluttet ved næste opstart. Sporet fortsætter, hvor det slap.
- **Pause:** GPS'en og notifikationen slukkes under pausen. Uret viser tid i
  bevægelse (uden pauser). Efter pausen fortsætter sporet, hvor det slap. En
  tur på pause er stadig på pause, hvis appen lukkes og åbnes igen.
- **Deling:** "Del GPX" åbner telefonens delemenu.
- Optagelsen stopper af sig selv, før GPX-filen når 10 MB.

## Upload til mitspor.dk (offline-kø)

Under "Gemte ture" kan hver tur uploades (`www/upload.js`,
`www/upload-visning.js`, `www/konto.js`).

- **Login:** appen logger ind via `/api/v1/login.php` og får et token, der
  sendes i headeren `X-Api-Token`. Tokenet gemmes i Keychain/Keystore med
  `capacitor-secure-storage-plugin` (MIT), aldrig i localStorage, og
  adgangskoden gemmes ikke. "Log ud" tilbagekalder tokenet på sitet.
- **Upload:** brugeren vælger titel og aktivitet, og turen sættes i kø
  (`meta.upload` i `meta.json`). Turen uploades som privat med turens id som
  `client_upload_id`, så et nyt forsøg aldrig giver en dublet.
- **Køen** sendes ved opstart, når nettet kommer tilbage, når appen vækkes,
  efter login og hvert minut, mens appen er åben. Uden net venter turen.
  Serverfejl (5xx, 429) prøves igen med stigende pause (30 s til 30 min).
  Afviser sitet turen (fx ugyldig GPX eller kvote), vises fejlen, og turen
  kan uploades igen. Er tokenet udløbet, bliver turen i køen til næste login.
- Aktivitetstyperne hentes ved login og gemmes (localStorage), så en tur
  kan sættes i kø uden net.

## Fælles login i appen og på sitet

Appen og sitet i WebView'et er logget ind med samme konto
(`www/app.js`, `www/konto.js`; på sitet `app/app_login.php`).

Der vises ingen mellemsider: appen sender oplysningerne i WebView'ets
user-agent, `MitSporApp/3 (par=…; tok=…; kode=…)`.

- **Appen → sitet (`kode`):** er appen logget ind, henter den en engangskode
  (`/api/v1/web-login-code.php`) og åbner `/app-login.php?til=…`, der logger
  sitet ind og omdirigerer straks.
- **Sitet → appen (`par`):** appen laver en ny hemmelighed, hver gang sitet
  åbnes, og sender dens SHA-256. Logger brugeren ind på sitet, kobles hashen
  til kontoen. Efter hver side (`browserPageNavigationCompleted`) og når
  sitet lukkes, prøver appen at veksle hemmeligheden til et token
  (`/api/v1/exchange-code.php`).
- **Log ud (`tok`):** appen sender SHA-256 af sit token. Log ud på sitet
  tilbagekalder tokenet; appen opdager det, når sitet lukkes. Log ud i appen
  rydder WebView'ets cookies, næste gang sitet åbnes.
- user-agent kommer i serverens adgangslog, så der sendes kun hashes og en
  kode, der bruges ved første forespørgsel og gælder i 60 sekunder.

WebView'et har ingen topbar. Fra `MitSporApp/3` viser sitet selv knappen
"‹ Appen" nederst til venstre (`app/app_tilbage.php`). Den fører til
`/app-luk.php`, og når den side er indlæst, lukker appen WebView'et.
Tilbage i sitets historik: swipe fra venstre kant (iOS) eller
tilbageknappen (Android).

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

Ikonet på appens startside (`www/img/ikon.png`, 256×256) er en nedskaleret
kopi af `assets/icon-only.png` og skal laves igen, når ikonet ændres, fx:

```bash
php -r '$s=imagecreatefrompng("assets/icon-only.png"); imagepng(imagescale($s,256,256,IMG_BICUBIC),"www/img/ikon.png",9);'
```

Værktøjet omformaterer `android/app/src/main/AndroidManifest.xml` uden at
ændre indholdet; den ændring kan rulles tilbage med `git checkout`.
