<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/vejledning.php';

vejledning_start('planlaeg', 'Planlæg næste tur', 'Tegn en rute på kortet i MitSpor, send den til dem, du skal af sted med, og hent den til dit GPS-ur. Trin for trin.');
echo vj_overskrift('planlaeg');
?>
    <p class="vj-situation">
        På lørdag skal du på vandretur i Rold Skov med to venner. Du vil gerne tegne ruten på forhånd, så I
        ved, hvor langt det er, og så I kan følge den på dagen.
    </p>

    <p>
        Indtil nu har vi talt om ture, du har optaget. En tur, du selv tegner på kortet, kalder MitSpor en
        <strong>rute</strong>. En optaget tur kaldes et <strong>spor</strong>. Forskellen forklares nederst.
    </p>

    <div class="vj-bruge">
        <p>Det skal du bruge</p>
        <ul>
            <li>En computer med mus. Du skal klikke mange gange på kortet, og det er meget nemmere på en stor skærm.</li>
        </ul>
    </div>

    <h2>Tegn ruten</h2>

    <div class="vj-trinliste">

    <?= vj_trin('Vælg Tegn ny rute', '
        <p>Gå ind på mitspor.dk, og åbn dit <strong>kontrolpanel</strong>. Klik på <strong class="knap">Tegn ny rute</strong>
        øverst. Du finder også <strong>Tegn ny rute</strong> på forsiden, når du er logget ind.</p>
    ', vj_billede('pc-plan-ny.webp', 'Kontrolpanelets øverste linje med knappen Tegn ny rute fremhævet', '', 'computer')) ?>

    <?= vj_trin('Find stedet på kortet', '
        <p>Editoren åbner med et kort. Træk i kortet med musen, og zoom med musehjulet, til du kan se stierne,
        hvor turen skal begynde.</p>
    ') ?>

    <?= vj_trin('Klik ruten ind', '
        <p>Klik på kortet, hvor turen begynder. Klik derefter et stykke længere henne ad stien, og så fremdeles.
        Hvert klik bliver en blå prik, og prikkerne forbindes med en streg.</p>
        <p>Stregen går lige fra prik til prik. Klik derfor ved hvert sving, så ruten følger stien.</p>
    ', vj_billede('pc-plan-tegner.webp', 'Editoren med de første otte prikker af ruten', '', 'computer')) ?>

    <?= vj_trin('Tegn hele vejen rundt', '
        <p>Fortsæt, til ruten er færdig. Øverst står, hvor mange punkter og hvor mange kilometer ruten er indtil nu.</p>
        <ul>
            <li><strong>Ramte du forkert?</strong> Klik på <strong>Fortryd</strong>.</li>
            <li><strong>Skal en prik flyttes?</strong> Tag fat i den med musen, og træk den på plads.</li>
            <li><strong>Mangler der en prik midt på ruten?</strong> Klik på stregen mellem to prikker.</li>
            <li><strong>Skal en prik væk?</strong> Klik på den, og vælg <strong>🗑 Slet punkt</strong>.</li>
        </ul>
    ', vj_billede('pc-plan-tegnet.webp', 'Den færdige rundtur i Rold Skov, 9,21 km', '', 'computer')) ?>

    <?= vj_trin('Hent højden', '
        <p>En tegnet rute ved ikke, hvor det går op og ned ad bakke. Det kan MitSpor finde ud af for dig: åbn menuen
        (☰) øverst til højre, vælg <strong>Rediger højdedata</strong>, og klik på
        <strong class="knap">Hent fra internettet</strong>.</p>
        <p>Så viser turens side en højdekurve og hvor mange højdemeter, I skal op.</p>
    ', vj_billede('pc-plan-hoejde.webp', 'Dialogen Rediger højdedata med knappen Hent fra internettet', '', 'computer')) ?>

    <?= vj_trin('Gem ruten', '
        <p>Klik på <strong class="knap">Gem</strong> øverst til venstre. Første gang kommer dialogen <strong>Gem ny rute</strong>:</p>
        <ul>
            <li>Skriv en <strong>titel</strong> og gerne en <strong>beskrivelse</strong>, fx hvor I mødes.</li>
            <li>Vælg <strong>aktiviteten</strong>, her Vandring.</li>
            <li>Lad <strong>Type</strong> stå på <strong>Rute</strong>.</li>
            <li>Sæt flueben ved <strong>Tillad download af GPX-filen</strong>, hvis vennerne skal kunne hente ruten
            til deres eget ur.</li>
        </ul>
        <p>Klik på <strong class="knap">Gem rute</strong>.</p>
    ', vj_billede('pc-plan-gem.webp', 'Dialogen Gem ny rute med titlen Rundtur i Rold Skov og typen Rute valgt', '', 'computer')) ?>

    </div>

    <h2>Del ruten, og tag den med</h2>

    <div class="vj-trinliste">

    <?= vj_trin('Send ruten til vennerne', '
        <p>Ruten er privat, som alle nye ture. Klik på <strong>Vis side</strong> i editoren, åbn menuen (☰), og vælg
        <strong>Del link</strong>. Kopiér linket, og send det til vennerne. Det er beskrevet i
        <a href="/vejledning/del-turen.php">Del turen med vennerne</a>.</p>
    ') ?>

    <?= vj_trin('Følg ruten på telefonen', '
        <p>På dagen kan I følge ruten direkte på telefonen. Åbn rutens side, og tryk på knappen med sigtekornet
        lige over <strong>+</strong> og <strong>−</strong>. Første gang skal du give lov til, at siden må se din placering.</p>
        <p>Nu viser en <strong>blå prik</strong>, hvor du er, og den følger med, mens I går. Så kan du hurtigt se, om I
        stadig er på ruten. Tryk på knappen igen for at skjule prikken.</p>
    ', vj_billede('web-plan-position.webp', 'Rold Skov-ruten med en blå prik for din position og knappen med sigtekornet fremhævet')) ?>

    <?= vj_trin('Hent ruten til dit ur', '
        <p>Har du et GPS-ur eller en kort-app, du plejer at følge ruter med, kan du hente ruten som en fil. Åbn
        menuen (☰) på turens side, og vælg <strong>Download GPX</strong>.</p>
        <p>I appen kan du bagefter vælge, hvilken app filen skal sendes til. På en computer havner den i din mappe
        med overførsler, og derfra kan du lægge den over på uret, som du plejer.</p>
    ', vj_billede('pc-plan-rute.webp', 'Rutens side med menuen åben og Download GPX fremhævet', '', 'computer')) ?>

    </div>

    <?= vj_godt('Hvad er en GPX-fil?', '
        <p>GPX er bare den slags fil, ture gemmes i. Næsten alle GPS-ure og kort-apps kan læse den. Du behøver
        aldrig selv at åbne den. Du skal bare vide, at det er den, du skal bruge, når en tur skal flyttes fra
        MitSpor til et andet sted, eller omvendt.</p>
    ') ?>

    <?= vj_godt('Rute eller spor?', '
        <p>Et <strong>spor</strong> er en tur, du har været på. Det har klokkeslæt, så turens side kan vise tid og
        hastighed. En <strong>rute</strong> er en tur, du har tegnet eller planlagt. Den har ingen tider, så siden viser
        kun vejen, længden og højden.</p>
        <p>Når I har gået turen, kan du optage den med appen. Så har du både planen og den rigtige tur.</p>
    ') ?>

    <?= vj_godt('Marker madpakkestedet', '
        <p>Du kan sætte prikker med tekst og billeder på ruten, fx hvor I spiser frokost, eller hvor der er en flot
        udsigt. Det fungerer som i <a href="/vejledning/farligt-sted.php">Advar om et farligt sted</a>.</p>
    ') ?>
<?php
vejledning_slut('planlaeg');
