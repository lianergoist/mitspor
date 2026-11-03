<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/vejledning.php';

vejledning_start('uden-net', 'På tur uden net', 'MitSpor-appen optager også uden mobildækning og sender turen, når du er på nettet igen. Sådan gør du.');
echo vj_overskrift('uden-net');
?>
    <p class="vj-situation">
        Du skal tre dage på fjeldet i Norge. Det meste af vejen er der ingen mobildækning, men du vil gerne optage
        alle dagene og se dem på kortet, når du kommer hjem.
    </p>

    <p>
        Det kan appen. GPS'en virker uden net, og turene gemmes på telefonen, indtil de kan sendes.
    </p>

    <h2>Før du tager af sted</h2>

    <div class="vj-trinliste">

    <?= vj_trin('Log ind hjemmefra', '
        <p>Sørg for at være logget ind i appen, mens du har net. Så kan du uploade turene undervejs, og de bliver
        sendt af sig selv senere.</p>
    ') ?>

    <?= vj_trin('Tænk på strømmen', '
        <p>Lad telefonen helt op, og tag gerne en powerbank med. Uden dækning bruger telefonen ekstra strøm på at lede
        efter net. Slå derfor gerne <strong>flytilstand</strong> til, når du optager. GPS\'en virker stadig på de fleste
        telefoner.</p>
    ') ?>

    </div>

    <h2>På turen</h2>

    <div class="vj-trinliste">

    <?= vj_trin('Optag som altid', '
        <p>Tryk på <strong class="knap">Optag tur</strong> og <strong class="knap">Start optagelse</strong>, præcis som i
        <a href="/vejledning/foerste-tur.php">Din første tur</a>. Appen gemmer turen på telefonen løbende, mens du går.</p>
        <p>Om aftenen trykker du på <strong class="knap">Stop og gem</strong>. Næste morgen starter du en ny optagelse.
        Så får du én tur pr. dag.</p>
    ') ?>

    <?= vj_trin('Upload, selvom der ikke er net', '
        <p>Du kan godt trykke på <strong class="knap">Upload</strong> og give turen titel og aktivitet med det samme, mens
        du husker, hvad dagen bød på. Under turen står der så <strong>Venter på upload</strong>.</p>
        <p>Når telefonen får net igen, sender appen turen af sig selv. Har du lukket appen, sker det, næste gang du åbner
        den. Bagefter står der <strong>Uploadet til mitspor.dk (privat)</strong>.</p>
    ', vj_billede('app-offline-koe.webp', 'Gemte ture med Fjeldtur dag 2, der venter på upload')) ?>

    <?= vj_trin('mitspor.dk kræver net', '
        <p>Kortet med dine ture og resten af mitspor.dk ligger på nettet. Trykker du på
        <strong class="knap">Åbn mitspor.dk</strong> uden net, fortæller appen, at siden kan åbnes, når du har forbindelse igen.</p>
    ', vj_billede('app-offline-start.webp', 'Startsiden med beskeden Du er ikke på nettet')) ?>

    </div>

    <h2>Hvis optagelsen blev afbrudt</h2>

    <div class="vj-trinliste">

    <?= vj_trin('Fortsæt, hvor du slap', '
        <p>Løb telefonen tør for strøm, eller genstartede den midt på turen, er turen ikke tabt. Næste gang du åbner
        optageren, spørger appen, hvad du vil gøre:</p>
        <ul>
            <li><strong class="knap">Fortsæt optagelsen</strong>, hvis du stadig er på vej.</li>
            <li><strong class="knap">Afslut og gem</strong>, hvis turen er slut. Så kan du uploade den som en almindelig tur.</li>
        </ul>
        <p>Det stykke, du gik, mens telefonen var slukket, kommer ikke med. Der bliver bare en lige streg mellem det
        sidste punkt før og det første punkt efter.</p>
    ', vj_billede('app-offline-afbrudt.webp', 'Optageren spørger, om den afbrudte tur skal fortsættes eller afsluttes')) ?>

    </div>

    <?= vj_godt('Livesending uden net', '
        <p>Sender du live, og der er et stykke uden dækning, gemmer appen dine punkter og sender dem, så snart der er net
        igen. Familien ser altså et hul i et stykke tid, og så kommer resten. Se
        <a href="/vejledning/foelg-med.php">Lad familien følge med</a>.</p>
    ') ?>

    <?= vj_godt('Turene bliver på telefonen', '
        <p>Dine ture ligger under <strong>Gemte ture</strong> i optagerens menu (☰), indtil du selv sletter dem, også efter
        de er uploadet. Så har du altid en kopi.</p>
    ') ?>

    <?= vj_godt('Upload mislykkedes?', '
        <p>Står der <strong>Upload mislykkedes, prøver igen senere</strong>, prøver appen igen af sig selv. Du kan også
        trykke på <strong class="knap">Prøv igen</strong>.</p>
    ') ?>
<?php
vejledning_slut('uden-net');
