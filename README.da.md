# MitSpor

[English](README.md) | **Dansk**

[mitspor.dk](https://mitspor.dk) samler dine ture på ét kort. Del ruter og spor, til vands og til lands:
upload GPX-filer, tegn nye ruter, optag turen med appen, og del det hele med et link.
Gratis, uden reklamer og uden sporingscookies.

## Funktioner

- **Ruter:** upload GPX fra uret, telefonen eller GPS'en, eller tegn en ny rute på kortet. Hver tur får
  sin egen side med afstand, tid, fart og højdeprofil, og den kan afspilles på kortet.
- **Deling:** en rute er offentlig (kan findes i søgningen og på kortet) eller privat (kun via et del-link).
  Du bestemmer selv, om andre må hente GPX-filen.
- **Grupper:** flerdagsture samles i en gruppe, så etaperne vises som én tur.
- **Editor:** flyt og slet punkter, udtynd sporet, hent højdedata, og indsæt waypoints, kommentarer og billeder.
- **App og live:** Android- og iOS-appen optager turen med GPS, også med slukket skærm og uden net, og kan
  sende turen live, så andre kan følge med på kortet.
- **Dine data:** kortet hentes fra OpenStreetMap via sitets egen tile-cache, så OpenStreetMap ikke ser,
  hvad brugerne kigger på. Brugerne kan hente alle deres data og slette deres konto.

## Teknik

- PHP med `declare(strict_types=1)`, uden framework, og MySQL/MariaDB.
- Almindelig JavaScript og CSS uden build-trin. Kort med [Leaflet](https://leafletjs.com/).
  Bibliotekerne ligger i `public/vendor/`.
- PHP-udvidelser: `pdo_mysql`, `mbstring`, `gd` (med FreeType), `dom`.
- Mobilappen er bygget med [Capacitor](https://capacitorjs.com/), se [`mobile/README.md`](mobile/README.md).

## Mappestruktur

```
app/          Logik og konfiguration (uden for webroden): login, CSRF, GPX, ruter, billeder,
              tile-cache, rate limiting, mail m.m.
public/       Webroden: sider, AJAX-endpoints (*-ajax.php), assets/, editor/, admin/,
              api/v1/ (JSON-API til appen), vendor/
mobile/       Capacitor-appen (www/, android/, ios/)
sql/          schema.sql og daterede skemaændringer
prune-*.php   Oprydning, køres af cron (tile-cache, sikkerhedslog, inaktive konti)
generate-missing-thumbnails.php   Laver manglende miniaturer til billeder
codemagic.yaml                    Bygning af appen i Codemagic
```

## Opsætning

1. Opret en database, og indlæs `sql/schema.sql`.
2. Kopiér `app/config.example.php` til `app/config.php`, og udfyld den (database, mail, `APP_BASE_URL`,
   `DATA_BASE_PATH` m.m.). `config.php` er ikke i git.
3. Peg webserverens DocumentRoot på `public/`. GPX-filer gemmes i `DATA_BASE_PATH` uden for webroden.
4. Sæt cron op til oprydningsscripts, fx:

   ```
   0 3 * * 0  php /sti/til/mitspor/prune-tile-cache.php
   ```

Skemaændringer lægges som filer i `sql/` med dato i navnet, så de kan køres på produktion.

## Mobilappen

Appen (`dk.mitspor.app`) består af lokale sider med GPS-optageren, der virker uden net, og en in-app
WebView, der viser mitspor.dk. Optagelser gemmes løbende på telefonen og uploades via `public/api/v1/`,
når der er net. Detaljer om optager, upload, login og bygning står i [`mobile/README.md`](mobile/README.md).

## Licens

MIT, se [LICENSE](LICENSE).
