<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/vejledning.php';

vejledning_start('kom-i-gang', 'Kom i gang på 5 minutter', 'Hent MitSpor-appen, opret en gratis konto, og lær startsiden at kende. Trin for trin.');
echo vj_overskrift('kom-i-gang');
?>
    <p class="vj-situation">
        Du har hørt om MitSpor og vil gerne prøve det. Om fem minutter har du appen på telefonen og en
        konto, og så er du klar til din første tur.
    </p>

    <div class="vj-bruge">
        <p>Det skal du bruge</p>
        <ul>
            <li>Din mobiltelefon (iPhone eller Android).</li>
            <li>En e-mailadresse, du kan åbne på telefonen.</li>
        </ul>
    </div>

    <div class="vj-trinliste">

    <?= vj_trin('Hent appen', '
        <p>Læser du det her på telefonen, så tryk på mærket, der passer til din telefon, og installér appen.
        Den er gratis.</p>
        ' . vj_butikker() . '
        <p>Du kan også åbne <strong>App Store</strong> på en iPhone eller <strong>Google Play</strong> på en
        Android-telefon og søge efter <strong>MitSpor</strong>.</p>
    ') ?>

    <?= vj_trin('Åbn appen', '
        <p>Første gang du åbner appen, fortæller den kort, hvad du kan bruge den til.
        Tryk på <strong class="knap">Kom i gang</strong>.</p>
    ', vj_billede('app-intro.webp', 'Appens velkomst med knappen Kom i gang', 'Velkomsten vises kun første gang.')) ?>

    <?= vj_trin('Tryk på Opret konto', '
        <p>Nu står du på appens <strong>startside</strong>. Øverst til højre står <strong>Opret konto</strong> og
        <strong>Log ind</strong>. Tryk på <strong class="knap">Opret konto</strong>.</p>
        <p>Har du allerede en konto, så tryk på <strong class="knap">Log ind</strong> i stedet, og spring til trin 6.</p>
    ', vj_billede('app-start-ude.webp', 'Startsiden med knapperne Opret konto og Log ind', 'Opret konto står øverst til højre.')) ?>

    <?= vj_trin('Udfyld formularen', '
        <ul>
            <li><strong>E-mail:</strong> din e-mailadresse. Du bruger den, når du logger ind.</li>
            <li><strong>Adgangskode:</strong> mindst 10 tegn. Vælg en, du ikke bruger andre steder.</li>
            <li><strong>Vis-navn:</strong> dit navn, som andre ser det ved de ture, du vælger at vise for alle.
            Det kan være dit fornavn eller et kaldenavn.</li>
        </ul>
        <p>Sæt flueben ved <strong>Jeg accepterer privatlivspolitikken</strong>, og tryk på
        <strong class="knap">Opret konto</strong>.</p>
    ', vj_billede('web-opret.webp', 'Formularen Opret konto med e-mail, adgangskode og navn', 'Formularen åbner inde i appen.')) ?>

    <?= vj_trin('Bekræft din e-mail', '
        <p>Appen viser nu <strong>Tjek din indbakke</strong>. Lad den side være åben.</p>
        <ol>
            <li>Åbn din mail. Du har fået en mail fra MitSpor.</li>
            <li>Tryk på linket i mailen.</li>
            <li>Gå tilbage til MitSpor-appen.</li>
        </ol>
        <p>Appen opdager selv, at du har bekræftet, og logger dig ind. Du ser nu dit
        <strong>kontrolpanel</strong>, som er der, hvor dine ture samles. Det er tomt indtil videre.</p>
        <p>Tryk på <strong>← MitSpor</strong> øverst til venstre og derefter på <strong>‹ Appen</strong>
        for at komme tilbage til appens startside.</p>
    ') ?>

    <?= vj_godt('Kan du ikke finde mailen?', '
        <p>Kig i mappen med uønsket post (spam). Der kan også gå et par minutter, før mailen kommer.</p>
    ') ?>

    <?= vj_trin('Log ind, hvis appen beder om det', '
        <p>Som regel er du allerede logget ind. Ellers trykker du på <strong class="knap">Log ind</strong>
        på startsiden, skriver din e-mail og adgangskode og trykker på <strong class="knap">Log ind</strong>.</p>
        <p>Du er nu logget ind både i appen og på mitspor.dk, og du bliver ved med at være det.</p>
    ', vj_billede('app-login.webp', 'Appens side Log ind med e-mail og adgangskode')) ?>

    </div>

    <h2>Et kig rundt på startsiden</h2>

    <div class="vj-trinliste">
    <section class="vj-trin">
        <div class="vj-trin-tekst">
            <p>Når du er logget ind, ser startsiden sådan ud. Den har to store knapper:</p>
            <ul>
                <li><strong class="knap">Åbn mitspor.dk</strong> viser hjemmesiden inde i appen. Her ser du kortet
                med andres ture og dine egne ture, og her ændrer og deler du dem.</li>
                <li><strong class="knap">Optag tur</strong> er dér, du optager en ny tur. Det fortæller
                næste kapitel om.</li>
            </ul>
            <p>Knappen med de tre streger (☰) øverst til højre er <strong>menuen</strong>. Den har bl.a.
            <strong>Hjælp</strong> og <strong>Log ud</strong>.</p>
            <p>Er du inde på mitspor.dk, kommer du tilbage hertil med <strong>‹ Appen</strong> øverst til venstre
            på forsiden.</p>
        </div>
        <?= vj_billede('app-start-inde.webp', 'Startsiden med knapperne Åbn mitspor.dk og Optag tur', 'Startsiden, når du er logget ind.') ?>
    </section>
    </div>

    <?= vj_godt('Appen og hjemmesiden hænger sammen', '
        <p>Det er den samme konto, du bruger i appen og på mitspor.dk. En tur, du optager i appen, kan du
        bagefter se på hjemmesiden, både på telefonen og på en computer. Gå ind på <strong>mitspor.dk</strong>
        i computerens browser, og log ind med samme e-mail og adgangskode.</p>
    ') ?>

    <p>Du er klar. I næste kapitel tager vi på tur.</p>
<?php
vejledning_slut('kom-i-gang');
