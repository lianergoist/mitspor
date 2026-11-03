<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/vejledning.php';

vejledning_start('farligt-sted', 'Indsæt waypoint', 'Markér et sted på din tur med en tekst og et billede, mens du er der, og ret det bagefter på computeren. Trin for trin.');
echo vj_overskrift('farligt-sted');
?>
    <p class="vj-situation">
        Midt på løbeturen ligger et stort træ væltet hen over stien. Du vil gerne advare andre, der løber
        samme vej, og selv kunne huske, hvor det var.
    </p>

    <p>
        Det klarer du med et <strong>waypoint</strong>. Et waypoint er en prik på kortet med en titel,
        en tekst og gerne et billede. Den viser præcis, hvor noget er. Det kan være et farligt sted, men
        også en flot udsigt, en god bænk eller stedet, hvor I holdt frokost.
    </p>

    <div class="vj-bruge">
        <p>Det skal du bruge</p>
        <ul>
            <li>En tur, du er ved at optage (se <a href="/vejledning/foerste-tur.php">Din første tur</a>).</li>
            <li>Til sidst i kapitlet: en computer, hvis du vil rette i det bagefter.</li>
        </ul>
    </div>

    <h2>Mens du står ved stedet</h2>

    <div class="vj-trinliste">

    <?= vj_trin('Tryk på Indsæt waypoint', '
        <p>Stop op ved træet, og tryk på <strong class="knap">Indsæt waypoint</strong> i optageren.</p>
        <p>Prikken sættes dér, hvor du står, når du trykker. Du kan altså godt gå et par skridt, mens du skriver.
        Optagelsen kører videre imens.</p>
    ', vj_billede('app-wpt-knap.webp', 'Optageren midt på turen med knappen Indsæt waypoint fremhævet')) ?>

    <?= vj_trin('Skriv, hvad der er galt, og tag et billede', '
        <ul>
            <li><strong>Titel:</strong> kort og tydeligt, fx <em>Væltet træ over stien</em>.</li>
            <li><strong>Tekst:</strong> det, andre skal vide, fx hvordan man kommer forbi.</li>
            <li>Tryk på <strong class="knap">Tag billede</strong>, og tag et billede af stedet. Har du allerede
            taget det, kan du vælge det med <strong class="knap">Vælg billeder</strong>.</li>
        </ul>
        <p>Første gang spørger telefonen, om MitSpor må bruge kameraet. Tryk på <strong>Tillad</strong>.</p>
        <p>Tryk på <strong class="knap">Gem waypoint</strong>.</p>
    ', vj_billede('app-wpt-dialog.webp', 'Dialogen Indsæt waypoint med titel, tekst og et billede af et væltet træ', 'Foto: David Martin, CC BY-SA 2.0.')) ?>

    <?= vj_trin('Løb videre', '
        <p>Under knappen står der nu <strong>1 waypoint indsat</strong>. Du kan indsætte lige så mange,
        du vil, på den samme tur.</p>
        <p>Når du er hjemme, stopper og uploader du turen som altid. Billederne kommer med automatisk.</p>
    ', vj_billede('app-wpt-antal.webp', 'Optageren viser 1 waypoint indsat under knappen')) ?>

    </div>

    <h2>Se advarslen på kortet</h2>

    <div class="vj-trinliste">

    <?= vj_trin('Find prikken', '
        <p>Åbn turen, som du plejer (tryk på <strong class="knap">Vis</strong> under Gemte ture). På kortet er
        der nu en lille <strong>lilla prik</strong> på turen. Den er lilla, fordi der er et billede. Et waypoint
        uden billede er orange.</p>
    ', vj_billede('web-rute-wpt-prik.webp', 'Turens side med den lilla prik fremhævet på kortet')) ?>

    <?= vj_trin('Tryk på prikken', '
        <p>Så ser du billedet, titlen og teksten. Andre, der ser din tur, kan gøre det samme.</p>
        <p>Vil du have, at andre får advarslen, skal du dele turen. Det står i
        <a href="/vejledning/del-turen.php">Del turen med vennerne</a>.</p>
    ', vj_billede('web-rute-wpt.webp', 'Waypointet er åbnet og viser billedet, titlen og teksten')) ?>

    </div>

    <h2>Ret advarslen bagefter på computeren</h2>

    <p>
        Måske vil du rette titlen, skrive en tekst til billedet, lægge flere billeder på eller flytte prikken
        helt præcist hen, hvor træet ligger. Det gør du i <strong>editoren</strong>.
    </p>

    <?= vj_godt('Brug gerne en computer', '
        <p>Editoren virker også på mobilen, men det er meget nemmere at ramme de små prikker med en mus og
        på en stor skærm. Gå ind på <strong>mitspor.dk</strong> på computeren, og log ind med samme e-mail og
        adgangskode som i appen.</p>
    ') ?>

    <div class="vj-trinliste">

    <?= vj_trin('Åbn turen i editoren', '
        <p>Når du er logget ind, viser forsiden fire store felter. Klik på <strong>Kontrolpanel</strong>.
        Det er der, alle dine ture samles.</p>
        <p>Klik på turen i listen til venstre. Til højre kommer et lille kort og en række knapper. Klik på
        <strong class="knap">Rediger i GPX-editor</strong>.</p>
    ', vj_billede('pc-kontrolpanel.webp', 'Kontrolpanelet med løbeturen valgt og knappen Rediger i GPX-editor fremhævet', '', 'computer')) ?>

    <?= vj_trin('Zoom ind til den lilla prik', '
        <p>Editoren viser turen som en streg med mange <strong>blå prikker</strong>. Hver blå prik er et sted,
        telefonen gemte undervejs. Dem skal du ikke røre nu.</p>
        <p>Find den <strong>lilla prik</strong>, og zoom ind med musens hjul, så du kan se den tydeligt.</p>
    ', vj_billede('pc-editor-wpt.webp', 'Editoren zoomet ind med den lilla prik fremhævet mellem de blå prikker', '', 'computer')) ?>

    <?= vj_trin('Klik på prikken, og ret det, du vil', '
        <p>Dialogen <strong>Billeder</strong> åbner. Her kan du:</p>
        <ul>
            <li>rette <strong>titlen</strong> og <strong>beskrivelsen</strong> (den tekst, du skrev på turen),</li>
            <li>skrive en <strong>tekst til billedet</strong> i feltet ved siden af det,</li>
            <li>lægge flere billeder på med <strong>📷 Tilføj billeder</strong>,</li>
            <li>fjerne et billede med det røde <strong>✕</strong>.</li>
        </ul>
        <p>Klik på <strong class="knap">Gem</strong> nederst i dialogen.</p>
    ', vj_billede('pc-editor-billeder.webp', 'Dialogen Billeder med titlen Væltet træ over stien og billedet med tekst', '', 'computer')) ?>

    <?= vj_trin('Flyt prikken, hvis den ikke sidder helt rigtigt', '
        <p>GPS\'en rammer ikke altid helt præcist. Tag fat i den lilla prik med musen, og træk den hen, hvor
        træet faktisk ligger.</p>
    ') ?>

    <?= vj_trin('Gem turen', '
        <p>Øverst til venstre er knappen <strong class="knap">Gem</strong> blevet <strong>rød</strong>. Det betyder,
        at du har ændringer, der ikke er gemt endnu. Klik på den. Når den bliver grøn igen, er alt gemt.</p>
        <p>Med <strong>Vis side</strong> ser du turen, som andre ser den.</p>
    ', vj_billede('pc-editor-gem.webp', 'Editoren med den røde Gem-knap fremhævet efter at prikken er flyttet', '', 'computer')) ?>

    </div>

    <?= vj_godt('Kom du til at flytte noget forkert?', '
        <p>Klik på <strong>Fortryd</strong> ved siden af Gem. Du kan fortryde flere gange i træk. Så længe du ikke
        har klikket på Gem, er intet ændret på din tur.</p>
    ') ?>

    <?= vj_godt('Glemte du at sætte en prik undervejs?', '
        <p>Det kan du gøre bagefter i editoren. Klik på den blå prik, der er tættest på stedet, og vælg
        <strong>➕ Indsæt waypoint</strong> for kun tekst eller <strong>📷 Indsæt billede</strong> for at
        lægge billeder på med det samme. Husk at klikke på Gem bagefter.</p>
    ') ?>
<?php
vejledning_slut('farligt-sted');
