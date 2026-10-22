<?php
declare(strict_types=1);

/**
 * Instruktionsvideoerne i public/videoer/ (mp4, plakatbillede og undertekster
 * med samme navn). Bruges af videoer.php og af "Se video"-knapperne i hjælpen,
 * som åbnes af assets/videoer.js.
 */
function instruction_videos(): array
{
    return [
        'Kom i gang' => [
            '01-find-ruter'   => ['Find ruter på kortet', 'Forsiden, søgning og rutens side med grafer og afspilning.'],
            '02-konto'        => ['Opret konto og profil', 'Opret en konto, ret dit navn, skift e-mail og adgangskode.'],
        ],
        'Dine ture' => [
            '03-upload'       => ['Upload en GPX-fil', 'Titel, aktivitet, rute eller spor, synlighed og opdeling i en gruppe.'],
            '04-kontrolpanel' => ['Kontrolpanelet', 'Fanerne, rutens værktøjer, metadata, datafelter og udtynding.'],
            '05-editor'       => ['Tegn og rediger i editoren', 'Tegn en ny rute, flyt punkter, fortryd og gem.'],
            '06-waypoints'    => ['Waypoints og billeder', 'Waypoints med tekst og billeder, galleriet og Mine billeder.'],
            '07-grupper'      => ['Grupper og flerdagesture', 'Saml etaper i en gruppe, og bestem rækkefølge og synlighed.'],
            '08-del-link'     => ['Del en privat rute', 'Del en privat tur med et link, og slet linket igen.'],
            '12-live-web'     => ['Live-tracking: følg en tur', 'Opret en livesending, og følg turen på livesiden.'],
        ],
        'Appen' => [
            '09-kom-i-gang-app' => ['Kom i gang med appen', 'Kortet i appen, og log ind med din konto.'],
            '10-optag-tur'      => ['Optag en tur', 'Start, waypoints med billeder, pause og stop.'],
            '11-upload-app'     => ['Upload fra appen', 'Upload en gemt tur, og del den bagefter.'],
            '12-live-app'       => ['Send live fra appen', 'Vælg eller opret en livesending, og send din position.'],
        ],
        'Dine data' => [
            '13-dine-data'    => ['Dine data', 'Download dine data, privatlivspolitikken og sletning af kontoen.'],
        ],
    ];
}

/** Titlen på én video, eller null hvis den ikke findes. */
function instruction_video_title(string $key): ?string
{
    foreach (instruction_videos() as $videos) {
        if (isset($videos[$key])) {
            return $videos[$key][0];
        }
    }
    return null;
}

/** Appvideoerne er optaget på højkant. */
function instruction_video_portrait(string $key): bool
{
    return isset(instruction_videos()['Appen'][$key]);
}

/**
 * En "Se video"-knap, der afspiller videoen i en dialog (assets/videoer.js).
 * $start: sekund, videoen starter ved, så knappen kan vise netop det relevante stykke.
 */
function video_button(string $key, float $start = 0, ?string $label = null): string
{
    $title = instruction_video_title($key);
    if ($title === null) {
        return '';
    }
    $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    return '<button type="button" class="video-btn" data-video="' . $h($key) . '"'
        . ($start > 0 ? ' data-start="' . $h((string) $start) . '"' : '')
        . ' data-title="' . $h($title) . '"'
        . (instruction_video_portrait($key) ? ' data-portrait="1"' : '')
        . '><span class="video-btn-icon" aria-hidden="true">▶</span>' . $h($label ?? $title) . '</button>';
}
