<?php
declare(strict_types=1);

// =====================================================================
// Kopiér denne fil til config.php og udfyld med rigtige værdier.
// config.php bør IKKE ligge i webroot (public/), og bør ikke committes
// til git (læg den i .gitignore).
// =====================================================================

// --- Database ---
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'gpx_platform');
define('DB_USER', 'gpx_app'); // den ÉNE applikations-DB-bruger
define('DB_PASS', 'ret-mig');

// --- Mail ---
define('MAIL_FROM_ADDRESS', 'info@dit-domæne.dk');
define('MAIL_FROM_NAME', 'GPX-platform');
define('MAIL_ENVELOPE_SENDER', 'info@dit-domæne.dk'); // bruges til mail() sin "-f"

// --- App ---
define('APP_BASE_URL', 'https://dit-domæne.dk'); // ingen trailing slash
define('EMAIL_VERIFICATION_TTL_HOURS', 24);

// --- Rate limiting (registrering) ---
define('REGISTER_RATE_LIMIT_MAX_ATTEMPTS', 5);
define('REGISTER_RATE_LIMIT_WINDOW_SECONDS', 3600); // 1 time

// --- Rate limiting (login) ---
define('LOGIN_RATE_LIMIT_MAX_ATTEMPTS', 5);
define('LOGIN_RATE_LIMIT_WINDOW_SECONDS', 900); // 15 minutter

// --- Sikkerhedsnoter (IP-adresser m.m.) ---
// Rækker i login_attempts og rate_limit_log (og udløbne engangs-links)
// ældre end dette antal dage slettes af prune-security-logs.php. Skal
// matche det, der står i privatlivspolitikken (afsnit 6).
define('SECURITY_LOG_RETENTION_DAYS', 30);

// --- Password reset ---
define('PASSWORD_RESET_TTL_HOURS', 1);
define('PASSWORD_RESET_RATE_LIMIT_MAX_ATTEMPTS', 5);
define('PASSWORD_RESET_RATE_LIMIT_WINDOW_SECONDS', 3600); // 1 time

// --- E-mailskift (profil) ---
define('EMAIL_CHANGE_TTL_HOURS', 24);
define('EMAIL_CHANGE_RATE_LIMIT_MAX_ATTEMPTS', 5);
define('EMAIL_CHANGE_RATE_LIMIT_WINDOW_SECONDS', 3600); // 1 time

// --- Mobil-API (public/api/v1/) ---
// Et app-token udløber dette antal dage efter SIDSTE brug.
define('API_TOKEN_TTL_DAYS', 180);
// Uploads pr. bruger via API'et inden for tidsvinduet.
define('API_UPLOAD_RATE_LIMIT_MAX_ATTEMPTS', 30);
define('API_UPLOAD_RATE_LIMIT_WINDOW_SECONDS', 3600); // 1 time
// Origins der får CORS-headere (Capacitor-appen på iOS/Android).
define('API_CORS_ALLOWED_ORIGINS', ['capacitor://localhost', 'https://localhost']);
// Engangskoder, der forbinder login i appen og på sitet (app/app_login.php):
// levetid i sekunder og antal koder pr. bruger/IP inden for tidsvinduet.
define('APP_LOGIN_CODE_TTL_SECONDS', 60);
define('APP_LOGIN_CODE_RATE_LIMIT_MAX_ATTEMPTS', 60);
define('APP_LOGIN_CODE_RATE_LIMIT_WINDOW_SECONDS', 3600); // 1 time

// --- Udtynding af punkter ---
// Foreslå udtynding, hvis den typiske (median) logningsfrekvens er
// hyppigere end dette antal sekunder.
define('GPX_THINNING_SUGGEST_THRESHOLD_SECONDS', 10);

// --- GPX-lagring ---
// Absolut sti, UDEN trailing slash. Skift kun denne linje, når filerne
// flyttes til en anden server/sti (fx mitspor.dk).
define('DATA_BASE_PATH', '/var/www/rpix.dk/tracks/data/users');
define('MAX_UPLOAD_SIZE_BYTES', 10 * 1024 * 1024); // 10 MB pr. fil

// --- Billedlagring (waypoint-billeder) ---
// I MODSÆTNING til GPX-filerne ligger billeder i en almindelig,
// web-tilgængelig mappe under webroot — adgang styres kun af et
// tilfældigt filnavn, ikke af login/ejerskabstjek ved visning.
define('IMAGE_PUBLIC_DIR', '/var/www/rpix.dk/tracks/public/billeder');
define('IMAGE_PUBLIC_URL_BASE', 'billeder'); // relativ URL fra public/
define('IMAGE_MAX_BYTES', 8 * 1024 * 1024); // 8 MB pr. billede
define('IMAGE_ALLOWED_MIME_TYPES', [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
]);
// Længste side (i pixels) på den automatisk genererede thumbnail —
// se image_generate_thumbnail() i app/image_storage.php.
define('IMAGE_THUMBNAIL_MAX_DIMENSION', 320);

// --- Kort-tile-cache ---
// Egen cache af OpenStreetMap-tiles, så Leaflet beder om tiles fra
// vores egen server i stedet for direkte fra OSM ved hvert kald.
define('TILE_CACHE_DIR', '/var/www/rpix.dk/tracks/data/tile-cache');
define('TILE_UPSTREAM_TEMPLATE', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');
define('TILE_UPSTREAM_SUBDOMAINS', ['a', 'b', 'c']);
define('TILE_CACHE_MAX_AGE_SECONDS', 30 * 24 * 3600); // 30 dage, før en tile hentes igen
// Bruges kun af prune-tile-cache.php — en tile, der ikke er blevet
// genhentet (dvs. reelt ikke efterspurgt) i så lang tid, slettes helt.
// Sat markant højere end TILE_CACHE_MAX_AGE_SECONDS ovenfor, så en
// tile, der stadig bliver brugt jævnligt (og dermed får frisk
// filemtime ved hver genhentning), aldrig rammes ved en fejl.
define('TILE_CACHE_PRUNE_AGE_SECONDS', 90 * 24 * 3600); // 90 dage
define('TILE_MAX_ZOOM', 19);
// OSM's brugspolitik kræver en User-Agent, der reelt identificerer
// sitet — IKKE en browser-streng. Ret til jeres rigtige domæne/mail.
define('TILE_USER_AGENT', 'GPX-platform/1.0 (https://rpix.dk; info@rpix.dk)');

// --- Delingsbilleder (Open Graph) ---
// Mappe til de genererede rutebilleder (JPEG). SKAL ligge uden for webroot
// og være skrivbar for webserveren. Udelades konstanten, bruges
// "og-cache" ved siden af tile-cachen (dirname(TILE_CACHE_DIR)).
// define('OG_CACHE_DIR', '/var/www/mitspor.dk/data/og-cache');

// Mappe til de forenklede spor, gruppesiden (group-view.php) tegner på kortet.
// Uden for webroot og skrivbar for webserveren. Udelades konstanten, bruges
// "group-cache" ved siden af tile-cachen (dirname(TILE_CACHE_DIR)).
// define('GROUP_CACHE_DIR', '/var/www/mitspor.dk/data/group-cache');

// Mappe til de forenklede ruter (linjer) på forsidens oversigtskort — se
// app/overview_lines.php. Uden for webroot og skrivbar for webserveren.
// Udelades konstanten, bruges "line-cache" ved siden af tile-cachen
// (dirname(TILE_CACHE_DIR)). Kan altid genskabes: php backfill-overview-lines.php
// define('LINE_CACHE_DIR', '/var/www/mitspor.dk/data/line-cache');
