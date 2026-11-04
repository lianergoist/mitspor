# MitSpor

**English** | [Dansk](README.da.md)

[mitspor.dk](https://mitspor.dk) collects your trips on one map. Share routes and tracks, on water and on land:
upload GPX files, draw new routes, record the trip with the app, and share it all with a link.
Free, without ads and without tracking cookies. The user interface is in Danish.

## Features

- **Routes:** upload GPX from your watch, phone or GPS, or draw a new route on the map. Each trip gets
  its own page with distance, time, speed and elevation profile, and it can be replayed on the map.
- **Sharing:** a route is public (findable in search and on the map) or private (only via a share link).
  You decide whether others may download the GPX file.
- **Groups:** multi-day trips are collected in a group, so the stages are shown as one trip.
- **Editor:** move and delete points, thin out the track, fetch elevation data, and add waypoints, comments and images.
- **App and live:** the Android and iOS app records the trip with GPS, also with the screen off and without
  a connection, and can send the trip live so others can follow along on the map.
- **Your data:** map tiles are fetched from OpenStreetMap via the site's own tile cache, so OpenStreetMap does not see
  what users are looking at. Users can download all their data and delete their account.

## Tech

- PHP with `declare(strict_types=1)`, no framework, and MySQL/MariaDB.
- Plain JavaScript and CSS with no build step. Maps with [Leaflet](https://leafletjs.com/).
  Libraries are in `public/vendor/`.
- PHP extensions: `pdo_mysql`, `mbstring`, `gd` (with FreeType), `dom`.
- The mobile app is built with [Capacitor](https://capacitorjs.com/), see [`mobile/README.md`](mobile/README.md) (in Danish).

## Directory structure

```
app/          Logic and configuration (outside the web root): login, CSRF, GPX, routes, images,
              tile cache, rate limiting, mail etc.
public/       Web root: pages, AJAX endpoints (*-ajax.php), assets/, editor/, admin/,
              api/v1/ (JSON API for the app), vendor/
mobile/       The Capacitor app (www/, android/, ios/)
sql/          schema.sql and dated schema changes
prune-*.php   Clean-up, run by cron (tile cache, security log, inactive accounts)
generate-missing-thumbnails.php   Creates missing image thumbnails
codemagic.yaml                    App builds in Codemagic
```

## Setup

1. Create a database and load `sql/schema.sql`.
2. Copy `app/config.example.php` to `app/config.php` and fill it in (database, mail, `APP_BASE_URL`,
   `DATA_BASE_PATH` etc.). `config.php` is not in git.
3. Point the web server's DocumentRoot at `public/`. GPX files are stored in `DATA_BASE_PATH` outside the web root.
4. Set up cron for the clean-up scripts, e.g.:

   ```
   0 3 * * 0  php /path/to/mitspor/prune-tile-cache.php
   ```

Schema changes are added as files in `sql/` with the date in the name, so they can be run on production.

## The mobile app

The app (`dk.mitspor.app`) consists of local pages with the GPS recorder, which works offline, and an in-app
WebView showing mitspor.dk. Recordings are saved continuously on the phone and uploaded via `public/api/v1/`
when there is a connection. Details on the recorder, upload, login and builds are in [`mobile/README.md`](mobile/README.md) (in Danish).

## License

MIT, see [LICENSE](LICENSE).
