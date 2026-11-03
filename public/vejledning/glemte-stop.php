<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/vejledning.php';

vejledning_start('glemte-stop', 'Du glemte at stoppe optagelsen', 'Fjern bilturen hjem fra din tur, hvis du glemte at stoppe optagelsen. Trin for trin i editoren på computeren.');
echo vj_overskrift('glemte-stop');
?>
    <p class="vj-situation">
        Efter løbeturen satte du dig i bilen og kørte hjem, og først der opdagede du, at optagelsen stadig
        kørte. Nu er turen 27 km lang, og der er en lang streg ud ad landevejen. Den skal væk.
    </p>

    <p>
        Det klarer du på få minutter i <strong>editoren</strong>. Du markerer bilturen og sletter den.
        Resten af turen er uændret, også titlen, billederne og et eventuelt link, du har delt.
    </p>

    <div class="vj-bruge">
        <p>Det skal du bruge</p>
        <ul>
            <li>En computer med mus. Prikkerne er små, så det er meget nemmere end på mobilen.</li>
            <li>Turen skal være uploadet til mitspor.dk.</li>
        </ul>
    </div>

    <div class="vj-trinliste">

    <?= vj_trin('Se, hvad der er galt', '
        <p>Åbn turen på mitspor.dk. Efter løbeturen fortsætter stregen ud ad vejen, og under kortet ses
        bilturen som en flad linje i højdekurven.</p>
    ', vj_billede('pc-glemt-rute.webp', 'Turens side, hvor løbeturen fortsætter som en lang biltur mod vest', 'Løbeturen til højre og bilturen hjem mod vest.', 'computer')) ?>

    <?= vj_trin('Åbn turen i editoren', '
        <p>Gå til dit <strong>kontrolpanel</strong>, klik på turen i listen, og klik på
        <strong class="knap">Rediger i GPX-editor</strong>. Det er beskrevet med billeder i
        <a href="/vejledning/farligt-sted.php">Advar om et farligt sted</a>.</p>
    ') ?>

    <?= vj_trin('Find ud af, hvor bilturen begynder', '
        <p>I editoren er turen en række blå prikker. Hver prik er et sted, telefonen gemte.</p>
        <p>Er du i tvivl om, hvor du satte dig i bilen, så <strong>dobbeltklik</strong> på en prik. Så ser du
        bl.a. klokkeslæt og <strong>hastighed</strong>. Omkring 40–50 km/t er bilen, ikke dig.</p>
        <p>Et godt tegn er også, at prikkerne pludselig ligger langt fra hinanden. Det skyldes, at bilen kører
        meget længere, mellem hver gang telefonen gemmer et punkt.</p>
    ', vj_billede('pc-glemt-info.webp', 'Info om punktet med hastigheden 44,9 km/t', '', 'computer')) ?>

    <?= vj_trin('Vælg Markerings-tilstand', '
        <p>Øverst til venstre på kortet står <strong>🔵 Rute-tilstand</strong>. Klik på den, og vælg
        <strong>🟢 Markerings-tilstand</strong>. Nu kan du markere et stykke af turen uden at komme til at
        flytte prikkerne.</p>
    ', vj_billede('pc-glemt-tilstand.webp', 'Tilstandsvælgeren med Markerings-tilstand fremhævet', '', 'computer')) ?>

    <?= vj_trin('Klik på det første punkt af bilturen', '
        <p>Zoom ind med musehjulet, hvor løbeturen slutter, og klik på den prik, hvor bilturen begynder.
        Prikken bliver grøn, og under knapperne øverst står der <strong>Tryk på det sidste punkt af strækningen</strong>.</p>
    ', vj_billede('pc-glemt-foerste.webp', 'Editoren zoomet ind, hvor bilturen begynder, med den første prik markeret', '', 'computer')) ?>

    <?= vj_trin('Klik på det sidste punkt, og tryk på Slet', '
        <p>Zoom ud, find enden af bilturen, og klik på den sidste prik. Hele bilturen bliver grøn, og nederst
        på skærmen står, hvor mange punkter og kilometer du har markeret.</p>
        <p>Tryk på <strong class="knap">Slet</strong>, og tryk <strong>OK</strong>, når editoren spørger.</p>
        <p>Er markeringen forkert, så klik på en ny prik. Så begynder markeringen forfra med den prik som første punkt.</p>
    ', vj_billede('pc-glemt-markeret.webp', 'Bilturen er markeret med grønt, og knappen Slet er fremhævet nederst', '', 'computer')) ?>

    <?= vj_trin('Gem turen', '
        <p>Nu slutter turen, hvor du stoppede med at løbe, og øverst står den rigtige længde. Knappen
        <strong class="knap">Gem</strong> er rød. Klik på den, og vent, til den bliver grøn.</p>
        <p>Klik på <strong>Vis side</strong> for at se turen, som andre ser den.</p>
    ', vj_billede('pc-glemt-slettet.webp', 'Bilturen er væk, turen er 10,42 km, og den røde Gem-knap er fremhævet', '', 'computer')) ?>

    </div>

    <?= vj_godt('Fortryd, men kun før du gemmer', '
        <p>Indtil du klikker på Gem, kan du klikke på <strong>Fortryd</strong> og få bilturen tilbage, eller
        lukke editoren uden at gemme. Når du har gemt, er punkterne væk for altid.</p>
    ') ?>

    <?= vj_godt('Det samme virker andre steder på turen', '
        <p>Startede du optagelsen for tidligt, markerer du fra den første prik til der, hvor turen rigtig
        begynder. Og har GPS\'en lavet et mærkeligt hop midt på turen, kan du markere og slette det stykke.</p>
    ') ?>

    <?= vj_godt('Vil du beholde det hele?', '
        <p>I stedet for Slet kan du markere selve løbeturen og trykke på <strong>Gem som nyt spor</strong>. Så
        får du en ny tur med kun løbeturen, og den oprindelige bliver, som den er. Den nye tur får dog ikke
        waypoints og beskrivelse med.</p>
    ') ?>
<?php
vejledning_slut('glemte-stop');
