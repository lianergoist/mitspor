<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/vejledning.php';

vejledning_start('foerste-tur', 'Din første tur', 'Optag en løbetur med MitSpor-appen, og se den på kortet, når du kommer hjem. Trin for trin.');
echo vj_overskrift('foerste-tur');
?>
    <p class="vj-situation">
        Det er lørdag morgen, og du skal ud og løbe en tur i skoven. Du vil gerne kunne se bagefter,
        hvor du løb, hvor langt det var, og hvor lang tid det tog.
    </p>

    <div class="vj-bruge">
        <p>Det skal du bruge</p>
        <ul>
            <li>Telefonen med MitSpor, og du er logget ind (se <a href="/vejledning/kom-i-gang.php">Kom i gang</a>).</li>
            <li>Godt med strøm på telefonen. Du behøver ikke net, mens du løber.</li>
        </ul>
    </div>

    <h2>Før du løber</h2>

    <div class="vj-trinliste">

    <?= vj_trin('Åbn optageren', '
        <p>Åbn MitSpor, og tryk på <strong class="knap">Optag tur</strong> på startsiden.</p>
        <p>Du ser nu optageren. Uret står på 0:00:00, og afstanden er 0,00 km.</p>
        <p>De andre ting på siden, <strong>Send live</strong> og <strong>Vis sensorer</strong>, kan du
        lade være. Send live står på <strong>Nej</strong>, og det er det rigtige i dag.</p>
    ', vj_billede('app-optag-klar.webp', 'Optageren, før turen er startet, med knappen Start optagelse')) ?>

    <?= vj_trin('Tryk på Start optagelse, og giv lov til din placering', '
        <p>Gå udenfor, og tryk på <strong class="knap">Start optagelse</strong>.</p>
        <p><strong>Første gang</strong> spørger telefonen, om MitSpor må bruge din placering (på Android
        står der <em>lokation</em>). Ellers kan appen ikke se, hvor du løber.</p>
        <ul>
            <li>Lad <strong>Præcis</strong> være valgt. Med <em>Omtrentlig</em> bliver turen på kortet unøjagtig.</li>
            <li>Tryk på <strong class="knap">Mens appen bruges</strong>. På en iPhone hedder knappen
            <strong>Tillad, mens appen bruges</strong>.</li>
        </ul>
        <p>Det er nok, selvom du slukker skærmen undervejs. Telefonen ved, at appen er i gang med at optage.</p>
    ', vj_billede('app-tilladelse-placering.webp', 'Telefonen spørger, om MitSpor må få adgang til enhedens lokation', 'Spørgsmålet på en Android-telefon.')) ?>

    <?= vj_trin('Sig ja til notifikationer', '
        <p>Lige efter spørger telefonen måske, om MitSpor må sende dig notifikationer. Tryk på
        <strong class="knap">Tillad</strong>.</p>
        <p>Appen sender ikke reklamer eller beskeder. Notifikationen står bare på telefonen, mens du optager,
        så du kan se, at optagelsen kører, og hurtigt komme tilbage til appen.</p>
    ', vj_billede('app-tilladelse-notifikationer.webp', 'Telefonen spørger, om MitSpor må sende notifikationer', 'Begge spørgsmål kommer kun første gang.')) ?>

    <?= vj_godt('Hvad bruger appen din placering til?', '
        <p>MitSpor bruger kun din placering, mens du optager en tur, og kun når du selv har trykket på
        Start optagelse. Turen gemmes på din telefon og sendes først til mitspor.dk, når du selv vælger det.</p>
    ') ?>

    <?= vj_trin('Vent et øjeblik på GPS\'en', '
        <p>Telefonen skal lige finde ud af, hvor du er. Imens står der <strong>Venter på GPS-signal</strong>
        på en gul baggrund.</p>
        <p>Det tager som regel under et minut udendørs. Når der står fx <strong>GPS: ±4 m</strong>, har
        telefonen fundet dig, og du kan løbe.</p>
        <p>Står den gule tekst der længe, så gå et sted hen med frit udsyn til himlen, væk fra høje huse.</p>
    ', vj_billede('app-optag-venter.webp', 'Optageren venter på GPS-signal', 'Den gule tekst forsvinder, når GPS\'en har fundet dig.')) ?>

    </div>

    <h2>Mens du løber</h2>

    <div class="vj-trinliste">

    <?= vj_trin('Læg telefonen i lommen', '
        <p>Du kan slukke skærmen og putte telefonen i lommen. Optagelsen fortsætter, og så længe den kører,
        står der en notifikation på telefonen.</p>
        <p>Kigger du på optageren undervejs, kan du se:</p>
        <ul>
            <li><strong>Tiden</strong>, du har været af sted.</li>
            <li><strong>Afstand</strong>: hvor langt du er kommet.</li>
            <li><strong>Punkter</strong>: hvor mange gange appen har gemt, hvor du er. Det er dem, der bliver
            til en streg på kortet. Du behøver ikke gøre noget ved dem.</li>
        </ul>
    ', vj_billede('app-optag-koerer.webp', 'Optageren midt på turen: 0:31:47 og 5,62 km', 'Midt på turen.')) ?>

    <?= vj_trin('Hold pause, hvis du vil', '
        <p>Skal du stå stille et stykke tid, fx til en kop kaffe, så tryk på <strong class="knap">Pause</strong>.
        Uret og afstanden står stille, og GPS\'en slukker, så du sparer strøm.</p>
        <p>Tryk på <strong class="knap">Fortsæt</strong>, når du løber videre.</p>
        <p>Du behøver ikke holde pause ved et rødt lys. Den lille stilstand gør ingen skade.</p>
    ', vj_billede('app-optag-pause.webp', 'Optageren på pause med knappen Fortsæt')) ?>

    </div>

    <h2>Når du er hjemme</h2>

    <div class="vj-trinliste">

    <?= vj_trin('Stop optagelsen', '
        <p>Tryk på <strong class="knap">Stop og gem</strong>. Telefonen spørger, om du vil stoppe og
        gemme. Tryk på <strong>OK</strong>.</p>
        <p>Turen er nu gemt på telefonen.</p>
    ', vj_billede('app-optag-stop.webp', 'Optageren efter turen med knappen Stop og gem', 'Turen sluttede efter 10,42 km.')) ?>

    <?= vj_trin('Tryk på Upload', '
        <p>Listen <strong>Gemte ture</strong> kommer frem med det samme. Din tur står øverst og hedder
        indtil videre dag og klokkeslæt.</p>
        <p>Tryk på <strong class="knap">Upload</strong> for at sende turen til mitspor.dk.</p>
    ', vj_billede('app-ture.webp', 'Gemte ture med den nye tur og knappen Upload')) ?>

    <?= vj_trin('Giv turen et navn', '
        <p>Skriv en <strong>titel</strong>, som du kan genkende turen på, fx <em>Morgenløb i skoven</em>.</p>
        <p>Vælg en <strong>aktivitet</strong>, her <strong>Løb</strong>. Aktiviteten bestemmer bl.a. det lille
        billede, der viser turen på kortet.</p>
        <p>Tryk på <strong class="knap">Upload</strong>.</p>
    ', vj_billede('app-upload.webp', 'Siden Upload tur med titlen Morgenløb i skoven og aktiviteten Løb')) ?>

    <?= vj_trin('Se turen på kortet', '
        <p>Efter et øjeblik står der <strong>Uploadet til mitspor.dk (privat)</strong> under turen.
        Tryk på <strong class="knap">Vis</strong>.</p>
    ', vj_billede('app-ture-uploadet.webp', 'Turen er uploadet, og knappen Vis er fremhævet')) ?>

    <?= vj_trin('Det er din tur', '
        <p>Nu ser du turens egen side:</p>
        <ul>
            <li><strong>Kortet</strong> med den blå streg, hvor du løb. Du kan zoome med to fingre.</li>
            <li><strong>Øverst</strong> står titlen og hvor langt du løb.</li>
            <li><strong>Nederst</strong> er en kurve over, hvor du løb op og ned ad bakke (Højde).</li>
            <li>Med <strong>▶</strong> kan du afspille turen og se den lille figur løbe ruten igen.</li>
        </ul>
        <p>Du kommer tilbage til appen med <strong>← MitSpor</strong> og derefter <strong>‹ Appen</strong>.</p>
    ', vj_billede('web-rute.webp', 'Turens side med kortet, titlen og højdekurven', 'Turens side på mitspor.dk.')) ?>

    </div>

    <?= vj_godt('Din tur er privat', '
        <p>Når du uploader en tur, er den <strong>privat</strong>. Det betyder, at kun du kan se den. Ingen andre
        kan finde den, før du selv deler den. Hvordan du gør det, står i næste kapitel,
        <a href="/vejledning/del-turen.php">Del turen med vennerne</a>.</p>
    ') ?>

    <?= vj_godt('Strøm', '
        <p>GPS\'en bruger lidt mere strøm end normalt. Til en løbetur er det ikke noget problem, men skal du
        på en lang tur, så start med en opladt telefon, og brug <strong>Pause</strong>, når du holder længere pauser.</p>
    ') ?>

    <?= vj_godt('Lukkede du listen for tidligt?', '
        <p>Du finder altid dine ture igen under <strong>Gemte ture</strong> i optagerens menu (☰).
        Turene ligger på telefonen, indtil du selv sletter dem.</p>
    ') ?>
<?php
vejledning_slut('foerste-tur');
