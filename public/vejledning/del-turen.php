<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/vejledning.php';

vejledning_start('del-turen', 'Del turen med vennerne', 'Send et link til din tur på MitSpor, eller gør den offentlig, så alle kan se den. Trin for trin.');
echo vj_overskrift('del-turen');
?>
    <p class="vj-situation">
        Løbeturen gik godt, og du vil gerne vise den til dine veninder i jeres gruppe på Messenger.
        De skal kunne se turen uden at oprette en konto.
    </p>

    <p>Der er to måder at dele en tur på:</p>
    <ul>
        <li><strong>Send et link.</strong> Turen er stadig privat, men dem, der får linket, kan se den.
        Det er den nemmeste måde, når turen er til nogle bestemte personer.</li>
        <li><strong>Gør turen offentlig.</strong> Så kan alle se den og finde den på kortet på mitspor.dk.</li>
    </ul>

    <h2>Send et link til turen</h2>

    <div class="vj-trinliste">

    <?= vj_trin('Åbn turen', '
        <p>Åbn turens side. Du kan gøre det på to måder:</p>
        <ul>
            <li>I optageren: åbn menuen (☰), vælg <strong>Gemte ture</strong>, og tryk på <strong class="knap">Vis</strong>
            ved turen.</li>
            <li>På mitspor.dk: åbn dit <strong>kontrolpanel</strong>, tryk på turen i listen og derefter på
            <strong>Vis rute på kort</strong>.</li>
        </ul>
    ') ?>

    <?= vj_trin('Vælg Del link i menuen', '
        <p>Tryk på menuen (☰) øverst til højre på turens side, og vælg <strong>Del link</strong>.</p>
    ', vj_billede('web-rute-menu.webp', 'Turens menu er åben, og Del link er fremhævet')) ?>

    <?= vj_trin('Kopiér linket', '
        <p>MitSpor laver et link til turen. Tryk på <strong class="knap">Kopiér</strong>.</p>
    ', vj_billede('web-del-link.webp', 'Dialogen Del link med linket og knappen Kopiér')) ?>

    <?= vj_trin('Sæt linket ind i en besked', '
        <p>Åbn Messenger, SMS eller den app, du plejer at skrive i. Hold fingeren på tekstfeltet, vælg
        <strong>Indsæt</strong>, og send beskeden.</p>
        <p>Når dine veninder trykker på linket, ser de turen på kortet, præcis som du gør. De behøver ikke
        en konto eller appen.</p>
    ') ?>

    </div>

    <?= vj_godt('Linket virker kun til denne ene tur', '
        <p>Alle, der har linket, kan se turen, også hvis de sender det videre. Del det derfor kun med
        nogen, du stoler på.</p>
        <p>Fortryder du, så åbn <strong>Del link</strong> igen, og tryk på <strong class="knap">Slet link</strong>.
        Så virker linket ikke længere, og turen er helt privat igen.</p>
    ') ?>

    <h2>Vis turen for alle</h2>

    <p>
        Vil du gerne have, at alle kan se turen, også folk du ikke kender, kan du gøre den
        <strong>offentlig</strong>. Så kommer den med på kortet på mitspor.dk, hvor andre kan finde den
        og blive inspireret.
    </p>

    <div class="vj-advar">
        <p><strong>Tænk over, hvor turen starter.</strong> Starter du løbeturen ved din hoveddør, kan andre se,
        hvor du bor, når turen er offentlig. Start eventuelt optagelsen lidt væk fra hjemmet, eller behold
        turen privat og send et link i stedet.</p>
    </div>

    <div class="vj-trinliste">

    <?= vj_trin('Åbn turen i editoren', '
        <p>På turens side trykker du på menuen (☰) og vælger <strong>Åbn i editor</strong>.</p>
        <p><strong>Editoren</strong> er der, hvor du ændrer en tur. Den kan mange ting, men lige nu skal du
        kun bruge én af dem.</p>
    ') ?>

    <?= vj_trin('Vælg Rediger metadata', '
        <p>Tryk på menuen (☰) øverst til højre i editoren, og vælg <strong>Rediger metadata</strong>.</p>
        <p><em>Metadata</em> er bare et fint ord for oplysningerne om turen: titel, beskrivelse, og hvem der
        må se den.</p>
    ', vj_billede('web-editor-menu.webp', 'Editorens menu med Rediger metadata fremhævet')) ?>

    <?= vj_trin('Vælg Offentlig, og gem', '
        <p>Rul ned til <strong>Synlighed</strong>, og vælg <strong>Offentlig (alle kan se den)</strong>.</p>
        <p>Første gang skal du sætte flueben ved, at du forstår, at turens start og slut kan vise, hvor du bor.</p>
        <p>Tryk på <strong class="knap">Gem ændringer</strong>.</p>
    ', vj_billede('web-metadata.webp', 'Rediger metadata med Offentlig valgt og knappen Gem ændringer')) ?>

    </div>

    <?= vj_godt('Send linket til en offentlig tur', '
        <p>På turens side vælger du <strong>Del link</strong> i menuen (☰) og trykker på
        <strong class="knap">Kopiér</strong>. Det kan alle, der ser en offentlig tur, også andre end dig.</p>
    ') ?>

    <?= vj_godt('De andre valg i dialogen', '
        <ul>
            <li><strong>Tillad download af GPX-filen:</strong> så kan andre hente turen som en fil, fx til deres
            eget GPS-ur. Vil du ikke det, så fjern fluebenet. Hvad en GPX-fil er, står i
            <a href="/vejledning/ordbog.php#gpx-fil">ordbogen</a>.</li>
            <li><strong>Vær anonym som uploader:</strong> så står dit navn ikke ved turen.</li>
        </ul>
        <p>Du kan altid gøre turen privat igen på samme måde.</p>
    ') ?>

    <?= vj_godt('Privat eller offentlig?', '
        <p><strong>Privat</strong> betyder, at kun du kan se turen, og dem du sender et link til.
        <strong>Offentlig</strong> betyder, at alle kan se den, og at den kan dukke op, når nogen søger på nettet.
        Nye ture er altid private, så det er dig, der bestemmer.</p>
    ') ?>
<?php
vejledning_slut('del-turen');
