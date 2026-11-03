<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/vejledning.php';

vejledning_start('ferie', 'En ferie med flere dagsture', 'Saml flere ture til én ferie på MitSpor, så hele turen kan ses på ét kort med etaper. Trin for trin.');
echo vj_overskrift('ferie');
?>
    <p class="vj-situation">
        Du har gået fire dage på Gudenåstien og optaget en tur hver dag. Nu vil du samle dem, så hele ferien
        kan ses på ét kort, og så man kan bladre fra dag til dag.
    </p>

    <p>
        Det kaldes en <strong>gruppe</strong>. En gruppe samler flere ture, og hver tur i gruppen kaldes en
        <strong>etape</strong>. Turene er de samme som før. De hænger bare sammen nu.
    </p>

    <div class="vj-bruge">
        <p>Det skal du bruge</p>
        <ul>
            <li>Turene skal være uploadet til mitspor.dk.</li>
            <li>Mobilen eller en computer. Det virker ens begge steder.</li>
        </ul>
    </div>

    <h2>Saml turene</h2>

    <div class="vj-trinliste">

    <?= vj_trin('Åbn kontrolpanelet', '
        <p>I appen trykker du på <strong class="knap">Åbn mitspor.dk</strong> og derefter på <strong>Kontrolpanel</strong>.
        På en computer går du ind på mitspor.dk og klikker på <strong>Kontrolpanel</strong>.</p>
    ') ?>

    <?= vj_trin('Sæt kryds ved dagene', '
        <p>I listen over dine ture er der en lille firkant foran hver tur. Sæt kryds ved de fire dage.</p>
        <p>Øverst står der nu <strong class="knap">Gruppér (4)</strong>. Tryk på den.</p>
    ', vj_billede('web-gruppe-vaelg.webp', 'Kontrolpanelet med kryds ved Gudenåstien dag 1 til 4 og knappen Gruppér (4)')) ?>

    <?= vj_trin('Giv ferien et navn', '
        <p>Vælg <strong>Ny gruppe</strong>, og skriv en <strong>titel</strong>, fx <em>Vandreferie på Gudenåstien</em>,
        og gerne et par ord som beskrivelse.</p>
        <p>Tryk på <strong class="knap">Gruppér</strong>.</p>
        <p>Har turene klokkeslæt, og det har ture optaget med appen, kommer de selv i den rigtige rækkefølge.</p>
    ', vj_billede('web-gruppe-dialog.webp', 'Dialogen Gruppér valgte ruter med titlen Vandreferie på Gudenåstien')) ?>

    </div>

    <h2>Se hele ferien</h2>

    <div class="vj-trinliste">

    <?= vj_trin('Vis gruppen på kortet', '
        <p>Tryk på fanen <strong>Grupper</strong> i kontrolpanelet, vælg ferien, og tryk på
        <strong>Vis gruppe på kort</strong>.</p>
        <p>Nu ser du alle dagene på ét kort. Hver etape har sin egen farve og sit eget nummer, og i listen står,
        hvor langt du gik hver dag. Tryk på en etape for at åbne den.</p>
    ', vj_billede('web-gruppe-side.webp', 'Gruppens side med fire farvede etaper og listen over dagene')) ?>

    <?= vj_trin('Bladr fra dag til dag', '
        <p>Øverst på hver etapes side står <strong>Del af Vandreferie på Gudenåstien</strong> og fx
        <strong>Etape 2 af 4</strong>. Med pilene ◀ og ▶ går du til dagen før eller dagen efter.</p>
    ', vj_billede('web-gruppe-etape.webp', 'Etape 2 med feltet Del af Vandreferie på Gudenåstien og pilene til forrige og næste etape')) ?>

    </div>

    <h2>Ret i gruppen</h2>

    <div class="vj-trinliste">

    <?= vj_trin('Vælg Rediger gruppe', '
        <p>I kontrolpanelet vælger du gruppen under fanen <strong>Grupper</strong> og trykker på
        <strong>Rediger gruppe</strong>.</p>
    ', vj_billede('web-gruppe-vaerktoej.webp', 'Gruppens værktøjer i kontrolpanelet med Rediger gruppe fremhævet')) ?>

    <?= vj_trin('Ret det, du vil', '
        <ul>
            <li><strong>Rækkefølgen:</strong> træk i en etape, eller brug pilene ▲ og ▼.</li>
            <li><strong>Glemte du en dag?</strong> Tryk på <strong>+ Tilføj etaper</strong>.</li>
            <li><strong>Hører en tur ikke med?</strong> Tryk på det røde <strong>×</strong>. Turen forsvinder ikke,
            den er bare ikke en del af gruppen længere.</li>
            <li><strong>Synlighed:</strong> gør alle etaperne private eller offentlige på én gang.</li>
        </ul>
        <p>Tryk på <strong class="knap">Gem</strong>.</p>
    ', vj_billede('web-gruppe-rediger.webp', 'Dialogen Rediger gruppe med etaperne og valget af synlighed fremhævet')) ?>

    </div>

    <?= vj_godt('Vis ferien for familien', '
        <p>Andre kan kun se de etaper, der er <strong>offentlige</strong>. Er alle etaper private, kan kun du se gruppen.</p>
        <ul>
            <li>Vil du vise ferien for alle, så vælg <strong>Offentlig</strong> under Synlighed i
            <strong>Rediger gruppe</strong>. Så kommer den med på kortet på mitspor.dk som én tur. Tryk derefter
            på <strong>del-knappen</strong> øverst på gruppens side, og send linket.</li>
            <li>Vil du hellere beholde den privat, kan du sende et link til alle dine ture med
            <strong>Del alle mine ruter</strong>. Se <a href="/vejledning/alle-ture.php">Vis alle dine ture</a>.</li>
        </ul>
    ') ?>

    <?= vj_godt('Grupper bliver også til af sig selv', '
        <ul>
            <li>Uploader du flere filer på én gang fra en computer, kan du samle dem i en gruppe med det samme.</li>
            <li>Sender du live flere dage i træk, samles dagene automatisk i en gruppe. Se
            <a href="/vejledning/foelg-med.php">Lad familien følge med</a>.</li>
        </ul>
    ') ?>

    <?= vj_godt('Opløs eller slet', '
        <p><strong>Opløs gruppen</strong> nederst i Rediger gruppe fjerner kun gruppen. Turene bliver.
        <strong>Slet gruppen og alle etaper</strong> sletter også alle turene, så brug den kun, hvis du vil af med det hele.</p>
    ') ?>
<?php
vejledning_slut('ferie');
