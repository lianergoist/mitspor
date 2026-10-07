# MitSpor

Platform hvor brugere opretter en profil, uploader GPX-ruter, ser dem på et kort, redigerer dem i en editor og deler dem (enkeltruter og grupper). Produktionssitet: mitspor.dk. Brugergrænsefladen er på **dansk**, og kommentarer i koden skrives på dansk.

## Miljø (vigtigt)

- Dette er **DEV**: `/var/www/dev.mitspor.dk`, database `tracks_dev`.
- Produktion er `mitspor.dk` (`/var/www/mitspor.dk`, database `tracks`). **Rør den aldrig** herfra: ingen kommandoer mod produktionsmappen eller produktionsdatabasen.
- Ændringer går til produktion via git (`git pull` på produktionsserveren), ikke ved at kopiere filer.

## Stak

- PHP med `declare(strict_types=1)`, uden framework. Database: MySQL/MariaDB via `app/db.php`.
- Frontend: almindelig JavaScript og CSS, ingen build-trin. Kort med Leaflet. Bibliotekerne ligger i `public/vendor/`.

## Struktur

- `public/`: webroot. Sider, AJAX-endpoints (`*-ajax.php`), `assets/` (dashboard, kort, værktøjer), `editor/` (rute-editoren), `admin/`, `markers/` og `route-icons/` (ikoner).
- `app/`: logik og konfiguration, ligger **uden for webroot**. Fx `auth.php`, `csrf.php`, `gpx.php`, `route_*.php`, `image_*.php`, `tile_cache.php`, `rate_limit.php`, `mailer.php`.
- `prune-tile-cache.php` i roden køres af cron.
- GPX-filer ligger uden for webroot (`DATA_BASE_PATH`). Billeder ligger i `public/billeder/` og er offentlige, beskyttet af tilfældige filnavne.

## Regler

- **Rør aldrig `app/config.php`**, læs den ikke, og skriv aldrig indholdet i svar, commits eller logs. Den er ikke i git. Nye indstillinger tilføjes i `app/config.example.php`, og jeg ændrer selv `config.php`.
- Ret ikke i `public/vendor/`.
- Brug altid prepared statements. Alle POST-handlinger skal bruge CSRF-tjek (`app/csrf.php`). Behold rate limiting på login, registrering, nulstilling af adgangskode og mailskift.
- Skemaændringer (`ALTER`, `CREATE`): vis SQL'en og vent på ok, før den køres. Gem den som en fil i `sql/` med dato i navnet, så jeg kan køre den på produktion. Der er ingen skemafil i repoet endnu; `mysqldump --no-data tracks_dev` kan bruges til at lave `sql/schema.sql`.
- Brug rodrelative links som `/login.php` (det virker på `dev.mitspor.dk` og på produktion).
- Kontrollér PHP-filer med `php -l <fil>`, før du siger, at noget er færdigt.
- Ret kun det, jeg beder om. Foreslå større oprydning, men gør det ikke uopfordret.

## Arbejdsgang

- Én ny funktion eller fejlrettelse pr. branch (`git switch -c navn`) og små commits med danske beskrivelser.
- Vis `git diff` til sidst, og forklar kort, hvad der er ændret og hvordan jeg tester det på dev.
- Commit aldrig `app/config.php`, brugerbilleder eller datafiler.
## Mobilapp (Capacitor) – planlagt

- iOS- og Android-app med Capacitor. Koden ligger i mappen `mobile/` i repoets rod (ikke `app/`, den er PHP-logik).
- Appen har to dele: (1) en WebView, der viser den eksisterende side, og (2) en indbygget, lokal GPS-optager, der virker uden net.
- Ændringer i websiden kræver intet nyt app-build. Kun optageren og det native skal skal bygges.

### Optageren
- Baggrundsplacering via et vedligeholdt Capacitor-plugin. På Android køres den som en foreground service med synlig notifikation.
- Optag kun, når brugeren selv har trykket start.
- Skriv punkter løbende til lokal lagring (ikke kun i hukommelsen), så en tur kan genoptages efter crash eller genstart.
- Kassér punkter med dårlig nøjagtighed, brug et fornuftigt interval eller en minimumsafstand, og hold filen under `MAX_UPLOAD_SIZE_BYTES` (10 MB).
- GPX gemmes først på enheden og uploades, når der er net (offline-kø). Hver optagelse har et `client_upload_id` (UUID), så et genforsøg ikke giver dubletter.

### API (`public/api/v1/`)
- JSON, ingen session og ingen CSRF. Rate limiting bevares.
- Token pr. enhed i headeren `X-Api-Token`. Brug IKKE `Authorization`: dev ligger bag basic auth, og PHP-FPM kan fjerne den header.
- Tokens gemmes som SHA-256-hash i `api_tokens`, vises kun ved login og tilbagekaldes ved skift af kodeord og ved kontosletning. Log aldrig tokens.
- CORS kun for `capacitor://localhost` og `https://localhost`.
- Genbrug `create_route()` og `attempt_login()`. Kopiér ikke logik.
- I appen gemmes tokens i Keychain/Keystore, aldrig i localStorage, og kodeordet gemmes aldrig.
- Login i appen og i WebView forbindes med et engangslink, der veksles til en webside-session. Det er ikke lavet endnu.

### Bygning og test
- iOS bygges i cloud (fx Codemagic), da der ikke er en Mac. Det kræver et Apple Developer-medlemskab. Android kan bygges på Linux.
- GPS og baggrundsdrift kan ikke testes herfra. Det skal prøves på rigtige telefoner på rigtige ture.
- Læg aldrig signeringsnøgler eller certifikater (`*.keystore`, `*.jks`, `*.p8`, `*.p12`, `*.mobileprovision`, `local.properties`) i git eller i svar.

### Butikkrav
- Kontosletning skal kunne nås fra appen.
- `privatlivspolitik.php` skal opdateres med app, lokal lagring og token/enhedsnavn.
- Baggrundsplacering skal begrundes over for Apple og Google. Tjek de aktuelle regler.

### Rækkefølge
1. Token-API (egen branch)
2. App-skal med WebView
3. Optager med lokal lagring og GPX-eksport
4. Offline-kø og upload
5. Engangslink-login
6. Test på telefoner og indsendelse til butikkerne

API-ændringer og app-ændringer laves på hver deres branch.
