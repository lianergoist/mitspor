<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/vejledning.php';

vejledning_start('ordbog', 'Lille ordbog', 'De ord, du møder i MitSpor, forklaret kort og i hverdagssprog: spor, rute, waypoint, GPX og de andre.');
echo vj_overskrift('ordbog');

// Ord, forklaring (escapet HTML) og evt. kapitel med mere.
$ord = [
    ['Aktivitet', 'Hvad du lavede på turen, fx løb, cykling eller kajak. Den bestemmer, om turen tæller som en tur på vand eller på land.', 'udseende'],
    ['Anonym', 'Vælger du at være anonym ved en offentlig tur, står dit navn ikke ved den.', 'del-turen'],
    ['Del alle mine ruter', 'Ét link til et kort med alle dine ture, også de private. Godt til den nærmeste familie.', 'alle-ture'],
    ['Del link', 'Et link til én tur eller gruppe, som du kan sende til andre. Ved en privat tur er linket hemmeligt, og du kan slette det igen.', 'del-turen'],
    ['Download', 'At hente en tur som en GPX-fil, fx til dit GPS-ur. Andre kan kun hente din tur, hvis du har tilladt det.', 'planlaeg'],
    ['Editor', 'Det sted på mitspor.dk, hvor du ændrer en tur: flytter prikker, sletter et stykke, tegner en ny rute eller sætter waypoints. Nemmest på en computer.', 'glemte-stop'],
    ['Etape', 'Én tur i en gruppe, fx én dag af en ferie over flere dage.', 'ferie'],
    ['Forsinkelse', 'Ved en livesending: hvor mange minutter efter andre ser din position.', 'foelg-med'],
    ['Gemte ture', 'Listen over de ture, appen har gemt på telefonen. Du finder den i optagerens menu (☰).', 'uden-net'],
    ['GPS', 'Den del af telefonen, der finder ud af, hvor du er, ved hjælp af satellitter. Den virker også uden net. <strong>±4 m</strong> betyder, at placeringen er præcis inden for ca. 4 meter.', 'foerste-tur'],
    ['GPX-fil', 'Den slags fil, ture gemmes i. Næsten alle GPS-ure og kort-apps kan læse den. Du behøver aldrig selv at åbne den.', 'planlaeg'],
    ['Gruppe', 'Flere ture samlet til én, fx alle dagene på en ferie. Hver tur i gruppen kaldes en etape.', 'ferie'],
    ['Højdedata', 'Hvor højt hvert punkt på turen ligger. Det giver højdekurven under kortet og antallet af <strong>højdemeter</strong>, altså hvor meget turen går op i alt.', 'planlaeg'],
    ['Kadence', 'Hvor mange gange du træder rundt i pedalerne, eller hvor mange skridt du tager, pr. minut.', 'puls'],
    ['Kontrolpanel', 'Din side på mitspor.dk med alle dine ture, grupper og livesendinger, og knapperne til at ændre dem.', 'kom-i-gang'],
    ['Livesending', 'Når appen sender din position, mens du optager, så andre kan følge dig på <strong>livesiden</strong>.', 'foelg-med'],
    ['Markering', 'I editoren kan du markere et stykke af turen ved at klikke på det første og det sidste punkt, fx for at slette det.', 'glemte-stop'],
    ['Markør', 'Figuren, der står ved turens start og bevæger sig langs turen, når den afspilles.', 'udseende'],
    ['Metadata', 'Oplysningerne om en tur: titel, beskrivelse, hvem der må se den og så videre. Du ændrer dem under Rediger metadata i editoren.', 'udseende'],
    ['Offentlig', 'En offentlig tur kan alle se, og den kommer med på kortet over offentlige ture.', 'del-turen'],
    ['Optageren', 'Den del af appen, hvor du optager en tur. Du kommer dertil med <strong>Optag tur</strong>.', 'foerste-tur'],
    ['Privat', 'En privat tur kan kun du se, og dem du har sendt et link til. Alle nye ture er private.', 'del-turen'],
    ['Puls', 'Hvor mange gange dit hjerte slår pr. minut. Måles med et pulsbælte.', 'puls'],
    ['Punkt', 'Et sted, telefonen gemte undervejs. I editoren er punkterne blå prikker, og stregen mellem dem er turen.', 'glemte-stop'],
    ['Rute', 'En tur, du har tegnet eller planlagt. Den har ingen tider, så den viser ikke hastighed.', 'planlaeg'],
    ['Spor', 'En tur, du har været på og optaget. Den har klokkeslæt, så den kan vise tid og hastighed.', 'planlaeg'],
    ['Udtynde', 'At fjerne nogle af punkterne på lige stykker, så turen fylder mindre. Det finder du i editorens menu (☰).', null],
    ['Upload', 'At sende en tur fra telefonen eller computeren op til mitspor.dk. Uden net venter appen, til der er forbindelse.', 'uden-net'],
    ['Watt', 'Hvor meget kraft du lægger i pedalerne. Måles med en wattmåler på cyklen.', 'puls'],
    ['Waypoint', 'En prik på turen med en titel, en tekst og gerne billeder, fx et farligt sted eller en flot udsigt. Lilla med billeder, orange uden.', 'farligt-sted'],
];
$kapitler = vejledning_kapitler();
?>
    <p class="vj-situation">
        Her er de ord, du møder i MitSpor og i vejledningen, forklaret kort. Følg linket for at læse mere.
    </p>

    <dl class="vj-ordbog">
        <?php foreach ($ord as [$ordet, $forklaring, $kapitel]): ?>
            <dt id="<?= vj_h(strtolower(str_replace([' ', 'æ', 'ø', 'å'], ['-', 'ae', 'oe', 'aa'], mb_strtolower($ordet)))) ?>"><?= vj_h($ordet) ?></dt>
            <dd>
                <?= $forklaring ?>
                <?php if ($kapitel !== null && !empty($kapitler[$kapitel]['klar'])): ?>
                    <a class="vj-ordbog-mere" href="/vejledning/<?= vj_h($kapitel) ?>.php">Læs mere i <?= vj_h($kapitler[$kapitel]['titel']) ?></a>
                <?php endif; ?>
            </dd>
        <?php endforeach; ?>
    </dl>
<?php
vejledning_slut('ordbog');
