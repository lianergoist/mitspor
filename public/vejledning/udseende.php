<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/vejledning.php';

vejledning_start('udseende', 'Metadata og markør', 'Giv din tur på MitSpor en god titel, en beskrivelse, den rigtige aktivitet og en figur på kortet. Trin for trin.');
echo vj_overskrift('udseende');
?>
    <p class="vj-situation">
        Din tur hedder stadig <em>Tur 3. nov. 2026 kl. 07.30</em>, der står ingen beskrivelse, og figuren på kortet er en
        løber, selvom du var ude at cykle. Inden du deler turen, vil du gerne give den et ordentligt udseende.
    </p>

    <h2>Titel og beskrivelse</h2>

    <div class="vj-trinliste">

    <?= vj_trin('Åbn turen i editoren', '
        <p>Åbn turens side, tryk på menuen (☰), og vælg <strong>Åbn i editor</strong>. Det virker både på mobilen og på
        en computer.</p>
    ') ?>

    <?= vj_trin('Vælg Rediger metadata, og skriv', '
        <p>Tryk på menuen (☰) øverst til højre i editoren, og vælg <strong>Rediger metadata</strong>.</p>
        <ul>
            <li><strong>Titel:</strong> kort og til at genkende, fx <em>Morgenløb i Marselisborgskovene</em>.</li>
            <li><strong>Beskrivelse:</strong> det, andre har glæde af at vide. Hvor starter man? Er der bakker, mudder eller
            et godt sted at holde pause?</li>
        </ul>
        <p>Tryk på <strong class="knap">Gem ændringer</strong>. Titel og beskrivelse gemmes med det samme.</p>
    ', vj_billede('web-udseende-metadata.webp', 'Dialogen Rediger metadata med felterne Titel og Beskrivelse fremhævet')) ?>

    </div>

    <h2>Aktivitet og figur på kortet</h2>

    <div class="vj-trinliste">

    <?= vj_trin('Åbn Vælg markør i kontrolpanelet', '
        <p>Åbn dit <strong>kontrolpanel</strong>, tryk på turen, og vælg <strong>Vælg markør</strong>.</p>
    ', vj_billede('web-markoer-knap.webp', 'Turens værktøjer i kontrolpanelet med Vælg markør fremhævet')) ?>

    <?= vj_trin('Vælg aktivitet og figur', '
        <ul>
            <li><strong>Aktivitetstype:</strong> hvad du lavede, fx Løb, Cykling eller Kajak. Den bestemmer, om turen kommer
            med, når nogen vælger <strong>Ruter på vand</strong> eller <strong>Ruter på land</strong> på kortet over
            offentlige ture.</li>
            <li><strong>Markør på kortet:</strong> figuren, der står ved turens start og bevæger sig langs turen, når den
            afspilles. <strong>Standard</strong> passer til aktiviteten. <strong>Emoji (K)</strong> og
            <strong>Emoji (M)</strong> er en kvinde eller en mand, og der er nåle i flere farver.</li>
        </ul>
        <p>Tryk på <strong class="knap">Gem</strong>.</p>
    ', vj_billede('web-markoer-dialog.webp', 'Dialogen Vælg markør med aktiviteten Løb og figurerne at vælge imellem')) ?>

    </div>

    <?= vj_godt('Giv titlen med det samme', '
        <p>Når du uploader en tur fra appen, beder appen om titel og aktivitet. Skriver du en god titel dér, er du næsten
        færdig. Se <a href="/vejledning/foerste-tur.php">Din første tur</a>.</p>
    ') ?>

    <?= vj_godt('Det kan du også ændre i Rediger metadata', '
        <ul>
            <li><strong>Type:</strong> rute eller spor. Se forskellen i <a href="/vejledning/planlaeg.php">Tegn en rute</a>.</li>
            <li><strong>Synlighed</strong>, <strong>download</strong> og om du vil være <strong>anonym</strong>. Se
            <a href="/vejledning/del-turen.php">Del turen med vennerne</a>.</li>
            <li><strong>Gruppe:</strong> hvilken ferie eller tur over flere dage turen hører til. Se
            <a href="/vejledning/ferie.php">Grupper og etaper</a>.</li>
        </ul>
    ') ?>

    <?= vj_godt('Hvad viser turens side?', '
        <p>Om turens side viser fx tid, hastighed og puls, vælger du under <strong>Visning af datafelter</strong> i
        kontrolpanelet. Se <a href="/vejledning/puls.php">Pulsbælte m.m.</a>.</p>
    ') ?>
<?php
vejledning_slut('udseende');
