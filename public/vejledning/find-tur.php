<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/vejledning.php';

vejledning_start('find-tur', 'Find en ny tur', 'Find inspiration blandt andres offentlige ture på MitSpor: søg, filtrér og hent en tur til dit eget ur. Trin for trin.');
echo vj_overskrift('find-tur');
?>
    <p class="vj-situation">
        Du er på ferie på Bornholm og vil gerne ud at gå en tur, men du kender ikke området. Måske har en anden
        allerede delt en god tur i nærheden.
    </p>

    <p>
        Alle ture, som andre har gjort <strong>offentlige</strong>, kan du se på et kort på mitspor.dk. Du behøver
        ikke engang være logget ind.
    </p>

    <div class="vj-trinliste">

    <?= vj_trin('Åbn kortet med offentlige ture', '
        <p>I appen trykker du på <strong class="knap">Åbn mitspor.dk</strong>. På forsiden trykker du på
        <strong>Offentlige ruter</strong>.</p>
        <p>Er du ikke logget ind, hedder knappen <strong class="knap">Vis offentlige ruter</strong>.</p>
    ', vj_billede('web-find-forside.webp', 'Forsiden med feltet Offentlige ruter fremhævet')) ?>

    <?= vj_trin('Kig rundt på kortet', '
        <p>Hver tur er en prik på kortet, der hvor den begynder. En blå cirkel med et tal er en tur over flere dage,
        og tallet er antallet af etaper.</p>
        <p>Zoom ind med to fingre. Når du kommer tæt nok på, får prikkerne også en streg, så du kan se, hvor turene går.</p>
        <p>Øverst kan du vælge kun at se <strong>Ruter på vand</strong>, fx kajak og kano, eller
        <strong>Ruter på land</strong>, fx gåture og cykelture.</p>
    ', vj_billede('web-find-kort.webp', 'Kortet over offentlige ture med valgene Alle, Ruter på vand og Ruter på land fremhævet')) ?>

    <?= vj_trin('Søg efter et sted eller en tur', '
        <p>Tryk på forstørrelsesglasset, og skriv et ord, fx navnet på et sted, en å eller en sø. MitSpor leder i turenes
        navne og beskrivelser, og listen viser de ture, der passer. Tryk på en titel for at åbne turen.</p>
    ', vj_billede('web-find-soeg.webp', 'Søgning efter Gudenå med to resultater')) ?>

    <?= vj_trin('Tryk på en prik', '
        <p>Tryk på en prik eller en streg på kortet. En lille boble viser turens navn og længde. Tryk på navnet
        for at åbne turen.</p>
    ', vj_billede('web-find-boble.webp', 'Boblen for Hammeren rundt fra Sandvig på kortet')) ?>

    <?= vj_trin('Se turen', '
        <p>Turens side viser, hvor turen går, hvor lang den er, og hvor meget den går op og ned. De lilla og orange prikker
        er steder, hvor den, der gik turen, har lagt billeder eller tekst. Tryk på dem.</p>
        <p>Med ▶ under kortet kan du se turen blive gået igen.</p>
    ', vj_billede('web-find-rute.webp', 'Turen Hammeren rundt fra Sandvig med lilla prikker og højdekurve')) ?>

    <?= vj_trin('Tag turen med', '
        <p>Vil du følge turen på dit GPS-ur eller i en kort-app, så åbn menuen (☰) og vælg
        <strong>Download GPX</strong>. Punktet findes kun, hvis den, der delte turen, har tilladt det.</p>
        <p>Du kan også sende turen til andre med <strong>Del link</strong> i samme menu.</p>
    ', vj_billede('web-find-menu.webp', 'Turens menu med Download GPX fremhævet')) ?>

    </div>

    <?= vj_godt('Farverne på kortet', '
        <ul>
            <li><strong>Blå:</strong> en optaget tur på vandet.</li>
            <li><strong>Rød:</strong> en optaget tur på land.</li>
            <li><strong>Lilla:</strong> en planlagt rute på vandet.</li>
            <li><strong>Orange:</strong> en planlagt rute på land.</li>
        </ul>
        <p>Forskellen på en optaget tur og en planlagt rute står i <a href="/vejledning/planlaeg.php">Planlæg næste tur</a>.</p>
    ') ?>

    <?= vj_godt('Hvad er en GPX-fil?', '
        <p>Det er den slags fil, ture gemmes i, og som GPS-ure og kort-apps kan læse. Læs mere i
        <a href="/vejledning/planlaeg.php">Planlæg næste tur</a>.</p>
    ') ?>

    <?= vj_godt('Del selv dine bedste ture', '
        <p>Kortet bliver bedre, jo flere der deler. Har du en tur, andre kunne have glæde af, så gør den offentlig,
        som beskrevet i <a href="/vejledning/del-turen.php">Del turen med vennerne</a>.</p>
    ') ?>
<?php
vejledning_slut('find-tur');
