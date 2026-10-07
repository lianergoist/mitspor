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
