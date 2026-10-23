<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/videoer.php';

$userId = require_login();
$user = current_user($userId);

if ($user === null) {
    $_SESSION = [];
    session_destroy();
    header('Location: ../login.php');
    exit;
}

$pdo = get_db();

$activityTypes = $pdo->query(
    'SELECT id, name_da FROM activity_types WHERE is_active = 1 ORDER BY (slug IN (\'water_other\',\'land_other\')) DESC, name_da ASC'
)->fetchAll();

// Brugerens grupper (etape-ture) til gruppevælgeren i metadata-dialogen.
$stmt = $pdo->prepare('SELECT public_id, title FROM route_groups WHERE user_id = :user_id ORDER BY title ASC');
$stmt->execute(['user_id' => $userId]);
$myGroups = $stmt->fetchAll();

// Editoren er ikke en selvstændig side, men et værktøj til kontrolpanelet:
//   editor/?route=123  redigér punkter/billeder/waypoints på en eksisterende rute
//   editor/?new=1      tegn en ny rute (oprettes først på serveren ved første Gem)
// Uden et af delene sendes brugeren tilbage til kontrolpanelet.
$initialRouteId = (string) ($_GET['route'] ?? '');
// Kun cifre er gyldige i et public_id — undgå at videresende vilkårlig input.
if (!preg_match('/^\d{1,8}$/', $initialRouteId)) {
    $initialRouteId = '';
}
$isNewRoute = $initialRouteId === '' && (string) ($_GET['new'] ?? '') === '1';

if ($initialRouteId === '' && !$isNewRoute) {
    header('Location: ../dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/svg+xml" href="/grafik/favicon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/grafik/favicon-32.png">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GPX-editor</title>

<link rel="stylesheet" href="../vendor/leaflet/leaflet.css" />
<link rel="stylesheet" href="style.css?v=44" />
<link rel="stylesheet" href="../assets/videoer.css?v=1">
</head>
<body data-image-prefix="<?= htmlspecialchars(IMAGE_PUBLIC_URL_BASE . '/' . $userId . '/', ENT_QUOTES, 'UTF-8') ?>">

<input type="hidden" id="csrf-token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" id="initial-route-id" value="<?= htmlspecialchars($initialRouteId, ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" id="initial-new-route" value="<?= $isNewRoute ? '1' : '' ?>">

<div id="toolbar">
  <div class="toolbar-group">
    <a href="../dashboard.php" id="btn-back" class="back-arrow" title="Tilbage" aria-label="Tilbage">&larr;</a>
  </div>

  <!-- Redigeringsknapperne: separate knapper som på rutens side, venstrestillet efter pilen. -->
  <div class="toolbar-group tool-group" role="group" aria-label="Redigering">
    <button id="btn-save-server" title="Gem på platformen" aria-label="Gem" disabled><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/></svg><span class="btn-text">Gem</span></button>
    <button id="btn-undo" title="Fortryd (Ctrl+Z)" aria-label="Fortryd" disabled><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 14L4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/></svg><span class="btn-text">Fortryd</span></button>
    <button id="btn-redo" title="Gendan (Ctrl+Y)" aria-label="Gendan" disabled><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 14l5-5-5-5"/><path d="M20 9H9.5a5.5 5.5 0 0 0 0 11H13"/></svg><span class="btn-text">Gendan</span></button>
    <button id="btn-view-page" title="Vis rutens side" aria-label="Vis side" disabled><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M1.5 12S5.5 5 12 5s10.5 7 10.5 7-4 7-10.5 7S1.5 12 1.5 12z"/><circle cx="12" cy="12" r="3"/></svg><span class="btn-text">Vis side</span></button>
  </div>

  <!-- "Hjælp" med samme ?-ikon som på rutens side, forsiden og i kontrolpanelet. -->
  <div class="toolbar-group toolbar-group-help">
    <button id="btn-help" title="Hjælp &amp; Vejledning" aria-label="Hjælp"><span class="help-icon" aria-hidden="true">?</span><span class="btn-text">Hjælp</span></button>
  </div>
</div>

<!-- Rutens titel, antal punkter og længde, og under dem den seneste besked. -->
<div id="route-title-bar">
  <div class="route-title-line">
    <strong>Rute:</strong> <span id="route-title-label" class="truncate">—</span>
    <span id="route-stats"></span>
  </div>
  <div id="status" role="status"></div>
</div>

<dialog id="edit-meta-dialog" class="modal-dialog">
  <button type="button" id="edit-meta-dialog-close" class="modal-close" aria-label="Luk">&times;</button>
  <button type="button" id="btn-open-meta-help" class="modal-close meta-help-btn" aria-label="Hjælp" title="Hjælp til rute/spor og synlighed">?</button>
  <h2>Gem ny rute</h2>

  <div id="edit-meta-errors" class="modal-errors" hidden></div>

  <div id="meta-panel">
    <label>
      Titel
      <input type="text" id="meta-title" maxlength="200" placeholder="Rutens titel">
    </label>

    <label>
      Beskrivelse
      <textarea id="meta-description" placeholder="Valgfri beskrivelse"></textarea>
    </label>

    <label>
      Aktivitetstype
      <select id="meta-activity">
        <?php foreach ($activityTypes as $type): ?>
          <option value="<?= (int) $type['id'] ?>"><?= htmlspecialchars($type['name_da'], ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <?php if (!empty($myGroups)): ?>
    <label>
      Gruppe (etape-tur)
      <select id="meta-group">
        <option value="">Ingen gruppe</option>
        <?php foreach ($myGroups as $g): ?>
          <option value="<?= htmlspecialchars($g['public_id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($g['title'], ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>

    <fieldset>
      <legend>Type</legend>
      <label class="checkbox-field">
        <input type="radio" name="meta-route-type" id="meta-route-type-rute" value="rute" checked>
        Rute (planlagt — viser ikke tid/hastighed)
      </label>
      <label class="checkbox-field">
        <input type="radio" name="meta-route-type" id="meta-route-type-spor" value="spor">
        Spor (GPS-optagelse — viser tid og hastighed)
      </label>
    </fieldset>

    <fieldset>
      <legend>Synlighed</legend>
      <label class="checkbox-field">
        <input type="radio" name="meta-visibility" id="meta-visibility-private" value="private" checked>
        Privat (kun dig)
      </label>
      <label class="checkbox-field">
        <input type="radio" name="meta-visibility" id="meta-visibility-public" value="public">
        Offentlig (alle kan se den)
      </label>
    </fieldset>

    <p class="notice-field" id="public-notice-field" hidden>
      <label class="checkbox-field">
        <input type="checkbox" id="meta-public-notice-ack">
        Jeg forstår at start-/slutpunkter i en offentlig rute kan afsløre min bopæl (GDPR).
      </label>
    </p>

    <label class="checkbox-field">
      <input type="checkbox" id="meta-allow-download">
      Tillad download af GPX-filen
    </label>

    <div id="meta-anonymous-field" hidden>
      <label class="checkbox-field">
        <input type="checkbox" id="meta-hide-uploader-name">
        Vær anonym som uploader (skjul mit navn)
      </label>
    </div>
  </div>

  <button type="button" id="btn-save-meta-dialog" class="btn btn-primary"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/></svg> Gem rute</button>
</dialog>

<dialog id="meta-help-dialog">
  <div class="dialog-header">
    <h2>Hjælp til rute/spor og synlighed</h2>
    <button id="btn-close-meta-help" class="close-btn" title="Luk">✖</button>
  </div>
  <div class="dialog-content">
    <h3>Rute eller Spor?</h3>
    <ul>
      <li>Vælg <strong>Spor</strong>, hvis filen er optaget med en GPS-enhed og indeholder tidsstempler — så vises tid og hastighed på rutens side, og aktiviteter med et dedikeret retningsikon (fx SUP, kajak) bruger det som bevægelig markør.</li>
      <li>Vælg <strong>Rute</strong>, hvis det er en planlagt/tegnet rute — den viser aldrig tid/hastighed, selv hvis filen skulle indeholde tidsstempler, og bruger altid en simpel blå prik som markør.</li>
      <li>Nye punkter, du selv klikker ind, får <b>ikke</b> et tidsstempel.</li>
    </ul>

    <h3>Synlighed og deling</h3>
    <ul>
      <li>Er ruten <strong>Offentlig</strong>, skal du bekræfte GDPR-boksen om, at start-/slutpunkter kan afsløre din bopæl.</li>
      <li>Du kan vælge at være <strong>anonym som uploader</strong> på en offentlig rute — dit navn vises så ikke, og ruten optræder heller ikke under "Andre ruter fra denne bruger" på andre af dine offentlige ruter.</li>
    </ul>
  </div>
</dialog>

<div id="map"></div>

<input type="file" id="image-file-input" accept="image/*" multiple style="display:none">

<dialog id="insert-waypoint-dialog" class="modal-dialog">
  <button type="button" id="insert-waypoint-dialog-close" class="modal-close" aria-label="Luk">&times;</button>
  <h2 id="insert-waypoint-dialog-title">Indsæt waypoint</h2>
  <div id="insert-waypoint-errors" class="modal-errors" hidden></div>
  <div class="modal-dialog-body">
    <label>
      Navn
      <input type="text" id="insert-waypoint-name" maxlength="200" placeholder="Navn på waypointet">
    </label>
    <label>
      Beskrivelse
      <textarea id="insert-waypoint-desc" placeholder="Valgfri beskrivelse"></textarea>
    </label>
  </div>
  <button type="button" id="btn-save-insert-waypoint" class="btn btn-primary"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/></svg> Gem</button>
  <button type="button" id="btn-delete-insert-waypoint" class="link-btn danger" hidden>🗑 Slet waypoint</button>
</dialog>

<dialog id="thin-dialog" class="modal-dialog">
  <button type="button" id="thin-dialog-close" class="modal-close" aria-label="Luk">&times;</button>
  <h2>Mange punkter</h2>
  <p id="thin-dialog-text"></p>
  <p class="thin-hint">Udtynding fjerner punkter på lige strækninger og bevarer sving, slingrende stier og bakker. Let, Middel og Kraftig tillader 2, 5 og 10 meters afvigelse. Du kan fortryde, og intet gemmes, før du trykker Gem.</p>
  <div class="thin-buttons">
    <button type="button" class="btn-primary" data-thin-tolerance="2">Let</button>
    <button type="button" class="btn-primary" data-thin-tolerance="5">Middel</button>
    <button type="button" class="btn-primary" data-thin-tolerance="10">Kraftig</button>
  </div>
  <button type="button" id="thin-dialog-skip" class="link-btn thin-skip">Fortsæt uden at udtynde</button>
</dialog>

<dialog id="edit-images-dialog" class="modal-dialog images-dialog">
  <button type="button" id="edit-images-dialog-close" class="modal-close" aria-label="Luk">&times;</button>
  <h2 id="edit-images-dialog-title">Billeder</h2>
  <div id="edit-images-errors" class="modal-errors" hidden></div>
  <div class="modal-dialog-body">
    <label>
      Titel
      <input type="text" id="edit-images-waypoint-name" maxlength="200" placeholder="Navn på waypointet">
    </label>
    <p class="images-dialog-hint">Træk i <strong>⠿</strong> for at ændre rækkefølgen — det er også den rækkefølge, billederne vises i på rutens side.</p>
    <ul id="edit-images-list" class="images-sortable-list"></ul>
    <div class="images-dialog-add-actions">
      <button type="button" id="btn-add-more-images" class="link-btn">📷 Tilføj billeder</button>
      <button type="button" id="btn-pick-existing-images" class="link-btn">🖼 Vælg blandt mine billeder</button>
    </div>
  </div>
  <button type="button" id="btn-save-edit-images" class="btn btn-primary"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/></svg> Gem</button>
  <button type="button" id="btn-delete-edit-images-waypoint" class="link-btn danger" hidden>🗑 Slet waypoint</button>
</dialog>

<dialog id="image-picker-dialog" class="modal-dialog images-dialog">
  <button type="button" id="image-picker-dialog-close" class="modal-close" aria-label="Luk">&times;</button>
  <h2>Vælg blandt mine billeder</h2>
  <div id="image-picker-errors" class="modal-errors" hidden></div>
  <div class="modal-dialog-body">
    <p class="images-dialog-hint">Klik på ét eller flere billeder for at vælge dem.</p>
    <div id="image-picker-grid" class="image-picker-grid"></div>
  </div>
  <button type="button" id="btn-add-picked-images" class="btn btn-primary">Tilføj valgte</button>
  <p class="image-picker-manage"><a href="/manage-images.php" id="link-manage-images">Administrér mine billeder</a> (se og slet uploadede billeder)</p>
</dialog>

<dialog id="help-dialog">
  <div class="dialog-header">
    <h2>Hjælp &amp; Vejledning</h2>
    <button id="btn-close-help" class="close-btn" title="Luk">✖</button>
  </div>
  <div class="dialog-content">
    <div class="video-btns"><?= video_button('05-editor') ?><?= video_button('06-waypoints') ?></div>
    <h3>Grundlæggende brug</h3>
    <ul>
      <li><strong>Ny rute:</strong> Klik på kortet for at placere trackpoints ét efter ét. Ruten oprettes først på platformen, når du klikker <strong>Gem</strong> — her får du lov at sætte titel, type og synlighed. Lukker du siden uden at gemme, er tegningen væk.</li>
      <li><strong>Åbn andre ruter / upload:</strong> Det foregår fra <strong>Kontrolpanelet</strong>. Her redigerer du punkter, billeder og waypoints på den rute, du kom hertil fra.</li>
      <li><strong>Indsæt på ruten:</strong> Klik på selve <b>ruten</b> (den blå streg) mellem to prikker for at indsætte et nyt punkt lige der.</li>
      <li><strong>Vis side:</strong> Åbner rutens side med kortet (samme som "Vis rute på kort" i kontrolpanelet), når ruten er gemt mindst én gang.</li>
      <li><strong>Pilen til venstre:</strong> Går ét trin tilbage til det, du kom fra — typisk kontrolpanelet eller rutens side. Har du ugemte ændringer, advarer browseren først.</li>
    </ul>

    <h3>Gem</h3>
    <ul>
      <li>Titel, beskrivelse, aktivitet, synlighed osv. ændres i <strong>Kontrolpanelet</strong> (Rediger metadata).</li>
      <li>Værktøjslinjens <strong>Gem</strong>-knap gemmer rutens geometri (trackpoints/waypoints) på platformen — og opretter ruten første gang, hvis den er ny. Den er rød, når der er ugemte ændringer, og bliver grøn, når alt er gemt.</li>
    </ul>

    <h3>Redigering af trackpoints (Blå prikker)</h3>
    <ul>
      <li><strong>Flyt:</strong> Træk i en blå prik for at flytte den præcist på plads.</li>
      <li><strong>Menu:</strong> Klik på en blå prik for at åbne dens menu. Her kan du indsætte et waypoint, tilføje et billede med det samme, eller slette punktet.</li>
    </ul>

    <h3>Waypoints (Orange / Lilla prikker)</h3>
    <ul>
      <li><strong>Opret:</strong> Klik på et eksisterende trackpoint (blå prik) og vælg "➕ Indsæt waypoint" (åbner en lille dialog med navn/beskrivelse), eller "📷 Indsæt billede" for at oprette et waypoint med billede(r) med det samme. Vil du sætte et waypoint et sted, der <b>ikke</b> ligger på selve ruten, kan du i stedet slå knappen øverst til venstre på kortet (ved siden af zoom-knapperne) til — den skifter fra "🔵 Rute" til "📍 Waypoint", og mens den er slået til, åbner et klik på kortet den samme lille menu som på et trackpoint ("➕ Indsæt waypoint" eller "📷 Indsæt billede") på det klikkede sted, i stedet for at tegne på ruten. Husk at slå den fra igen bagefter.</li>
      <li><strong>Rediger:</strong> Klik på et waypoint for at åbne den relevante dialog direkte — en <b>lilla</b> prik (har billeder) åbner billed-dialogen, en <b>orange</b> prik (kun tekst) åbner navn/beskrivelse-dialogen. Begge dialoger har også en "🗑 Slet waypoint"-knap. <b>Træk</b> i et waypoint for at flytte det.</li>
      <li><strong>Farve:</strong> Et waypoint er <b>orange</b>, hvis det kun har tekst, og bliver <b>lilla</b>, så snart det har mindst ét billedlink.</li>
      <li><strong>Billeder:</strong> Vælg "📷 Billeder" på et waypoint (eller "📷 Indsæt billede" på et trackpoint for at oprette et nyt waypoint med billeder samtidig) — det åbner billed-dialogen, hvor du med "📷 Tilføj billeder" kan vælge flere billeder på én gang, give hver sin kommentar, trække dem i den rækkefølge de skal vises i (træk i <strong>⠿</strong>), eller fjerne dem igen (✕). Med "🖼 Vælg blandt mine billeder" kan du i stedet genbruge et billede, du allerede har uploadet et andet sted, uden at skulle uploade det igen.</li>
    </ul>

    <h3>Fortryd</h3>
    <ul>
      <li><strong>Fortryd/Gendan:</strong> Brug knapperne Fortryd og Gendan i værktøjslinjen, eller Ctrl+Z og Ctrl+Y.</li>
      <li>Alle øvrige data i filen (puls, kadence osv.) bevares uændret, når du gemmer. Udtynding af punkter og valg af, hvad rutens side viser (<strong>Visning og datafelter</strong>, inkl. højdedata fra internettet), findes i <strong>Kontrolpanelet</strong>.</li>
    </ul>
  </div>
</dialog>

<script src="../vendor/leaflet/leaflet.js"></script>
<script src="../vendor/leaflet-path-drag/index.js"></script>
<script src="../vendor/sortablejs/Sortable.min.js"></script>
<script src="../assets/route-meta-dialog.js?v=2"></script>
<script src="../assets/gpx-tools.js?v=2"></script>
<script src="app.js?v=45"></script>
<script src="../assets/videoer.js?v=1"></script>
</body>
</html>
