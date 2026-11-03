<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/vejledning.php';

vejledning_start('alle-ture', 'Vis alle dine ture', 'Send ét link, der viser alle dine ture på MitSpor, også de private. Trin for trin.');
echo vj_overskrift('alle-ture');
?>
    <p class="vj-situation">
        Farmor vil gerne følge med i alle dine ture, også dem, du ikke viser for alle. Hun har ingen konto,
        og du gider ikke sende et nyt link, hver gang du har været ude.
    </p>

    <p>
        Med <strong>Del alle mine ruter</strong> får du ét link til et kort med alle dine ture og grupper,
        også de private. Det gælder også de ture, du optager fremover. Farmor skal bare gemme linket og åbne det,
        når hun vil se, hvad du har lavet.
    </p>

    <div class="vj-bruge">
        <p>Det skal du bruge</p>
        <ul>
            <li>Mobilen eller en computer, og du er logget ind.</li>
        </ul>
    </div>

    <div class="vj-trinliste">

    <?= vj_trin('Vælg Del alle mine ruter i appens menu', '
        <p>Åbn appen, tryk på menuen (☰) øverst til højre på startsiden, og vælg <strong>Del alle mine ruter</strong>.</p>
        <p>På en computer finder du det samme i menuen (☰) i dit kontrolpanel på mitspor.dk.</p>
    ', vj_billede('app-menu.webp', 'Appens menu på startsiden med Del alle mine ruter fremhævet')) ?>

    <?= vj_trin('Kopiér linket, og send det', '
        <p>Dit kontrolpanel åbner med linket. Tryk på <strong class="knap">Kopiér</strong>, og sæt det ind i en
        besked til farmor. Du kommer tilbage til appen med <strong>← MitSpor</strong> og derefter <strong>‹ Appen</strong>.</p>
        <p>Åbner du dialogen igen senere, er det det samme link. Det ændrer sig ikke, før du selv beder om det.</p>
    ', vj_billede('web-delalle-dialog.webp', 'Dialogen Del alle mine ruter med linket og knappen Kopiér')) ?>

    <?= vj_trin('Det ser farmor', '
        <p>Når farmor åbner linket, ser hun et kort med alle dine ture. Øverst står dit navn. Hun kan zoome ind og
        trykke på en tur for at åbne den.</p>
    ', vj_billede('web-delalle-kort.webp', 'Kortet med alle demokontoens ture og overskriften MitSpor Demos ruter', 'Kortet, som farmor ser det.')) ?>

    <?= vj_trin('Også de private ture', '
        <p>Farmor kan også åbne dine private ture fra kortet, præcis som du selv kan. Har du tilladt download af en
        tur, kan hun også hente den. Ellers kan hun kun se den.</p>
    ', vj_billede('web-delalle-rute.webp', 'En privat kajaktur, åbnet fra kortet uden at være logget ind')) ?>

    </div>

    <?= vj_godt('Fortryder du?', '
        <ul>
            <li><strong>Nyt link</strong> laver et nyt link. Det gamle holder op med at virke med det samme, også for
            dem, der allerede har det åbent. Send så det nye link til dem, der stadig skal have det.</li>
            <li><strong>Slet link</strong> fjerner linket helt. Så kan ingen længere se dine private ture.</li>
        </ul>
    ') ?>

    <?= vj_godt('Send det kun til nogen, du stoler på', '
        <p>Alle, der har linket, kan se alle dine ture, også dem du optager fremover, og dermed også hvor du plejer
        at starte dine ture. Send det derfor kun til familie og gode venner.</p>
    ') ?>

    <?= vj_godt('Linket virker gennem kortet', '
        <p>Det er linket til kortet, der giver adgang. Kopierer farmor adressen på en enkelt privat tur og sender
        den videre, virker den ikke for andre. Vil du dele én bestemt tur, så brug hellere
        <a href="/vejledning/del-turen.php">Del link på turen</a>.</p>
    ') ?>

    <?= vj_godt('Se alle dine egne ture', '
        <p>Vil du selv se alle dine ture på ét kort, så vælg <strong>Mine ruter på kort</strong> på forsiden af mitspor.dk,
        når du er logget ind.</p>
    ') ?>
<?php
vejledning_slut('alle-ture');
