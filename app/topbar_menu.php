<?php
declare(strict_types=1);

/*
 * Menuen (☰) til højre i topbaren på forsiden, kortet, rutens side og i
 * kontrolpanelet, og den fælles "Om MitSpor"-dialog. Editoren har sin egen
 * menu (editor/index.php), men bruger samme dialog.
 *
 * Menuen åbnes og lukkes af assets/topbar-menu.js, der også åbner "Om MitSpor".
 * Et menupunkt med et id (fx btn-open-route-help) er en knap, som siden selv
 * lytter på; menuen lukkes, når der klikkes på et punkt.
 */

/**
 * @param array<int, array{id?: string, href?: string, label: string, icon?: string}> $items
 *        Sidens egne punkter øverst (fx Hjælp). "Om MitSpor" og
 *        "Privatlivspolitik" kommer altid til sidst.
 * @param string $labelClass Klassen, der skjuler knapteksten på mobil
 *        (tb-label, eller btn-label på rutens side).
 */
function topbar_menu(array $items, string $labelClass = 'tb-label'): string
{
    $h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $items[] = ['id' => 'btn-open-about', 'label' => 'Om MitSpor'];
    $items[] = ['href' => '/privatlivspolitik.php', 'label' => 'Privatlivspolitik'];

    $html = '<div class="topbar-menu" data-topbar-menu>'
        . '<button type="button" class="btn" title="Menu" aria-label="Menu" aria-haspopup="true" aria-expanded="false" aria-controls="topbar-menu-list">'
        . '<svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/></svg>'
        . '<span class="' . $h($labelClass) . '">Menu</span></button>'
        . '<div class="topbar-menu-list" id="topbar-menu-list" role="menu" hidden>';
    foreach ($items as $item) {
        $icon = ($item['icon'] ?? '') === 'help' ? '<span class="help-icon" aria-hidden="true">?</span> ' : '';
        if (isset($item['href'])) {
            $html .= '<a role="menuitem" href="' . $h($item['href']) . '">' . $icon . $h($item['label']) . '</a>';
        } else {
            $html .= '<button type="button" role="menuitem" id="' . $h($item['id'] ?? '') . '">' . $icon . $h($item['label']) . '</button>';
        }
    }

    return $html . '</div></div>';
}

/** "Om MitSpor"-dialogen. Åbnes af menupunktet med id btn-open-about. */
function about_dialog(): string
{
    return <<<'HTML'
<dialog id="about-dialog">
    <div class="dialog-header">
        <h2>Om MitSpor</h2>
        <button type="button" id="about-dialog-close" class="close-btn" aria-label="Luk">&times;</button>
    </div>
    <div class="dialog-content">
        <p>Formålet med MitSpor er at dele spor og ruter. Man kan uploade sine spor (eller tegne en rute) og få den vist på et kort man kan linke til og dele med andre. Man kan også redigere spor og ruter: i den indbyggede GPX-editor kan man flytte punkter, indsætte kommentarer og billeder, udtynde punkter og hente højdedata, og i kontrolpanelet kan man fx vælge, hvad rutens side viser.</p>
        <p>Man kan markere spor og ruter som offentlige eller private.</p>
        <ul>
            <li><strong>Offentlig</strong> betyder, at alle kan se ruten. Man kan søge i titel og beskrivelse, og få vist alle de offentlige spor og ruter der matcher.</li>
            <li><strong>Privat</strong> betyder, at det kun er dig, der kan se sporet/ruten — med mindre du laver et del-link, der giver adgang til netop den rute.</li>
        </ul>
        <p>MitSpor er gratis at bruge. Alt det kræver er en emailadresse, og så er du i gang. Du kan downloade dine data og slette din profil som det passer dig. Ingen tracking, ingen sporings- eller reklamecookies, ingen deling af dine data med andre. Ægte gratis.</p>
        <p>Har du spørgsmål eller kommentarer, er du velkommen til at skrive til <a href="mailto:info@mitspor.dk">info@mitspor.dk</a>.</p>
        <p>Læs <a href="/privatlivspolitik.php">privatlivspolitikken</a>.</p>
    </div>
</dialog>
HTML;
}
