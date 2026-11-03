<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/vejledning.php';

vejledning_start('foelg-med', 'Lad familien følge med', 'Send din tur live med MitSpor, så familien derhjemme kan se på et kort, hvor du er. Trin for trin.');
echo vj_overskrift('foelg-med');
?>
    <p class="vj-situation">
        I morgen padler du rundt om Horsens Fjord i kajak. Familien vil gerne kunne se, hvor du er undervejs,
        så de ikke behøver at ringe og spørge.
    </p>

    <p>
        Det kalder vi en <strong>livesending</strong>. Mens du optager turen, sender appen din position til en
        side på mitspor.dk, <strong>livesiden</strong>. Den kan familien åbne med et link og følge med på et kort.
        De behøver hverken appen eller en konto.
    </p>

    <div class="vj-bruge">
        <p>Det skal du bruge</p>
        <ul>
            <li>Telefonen med MitSpor, og du er logget ind.</li>
            <li>Mobildata undervejs. Er der et stykke uden net, sender appen punkterne, når der er net igen.</li>
            <li>God strøm. Tag gerne en powerbank med på lange ture.</li>
        </ul>
    </div>

    <h2>Aftenen før: opret livesendingen</h2>

    <div class="vj-trinliste">

    <?= vj_trin('Vælg Opret ny livesending', '
        <p>Åbn appen, og tryk på <strong class="knap">Optag tur</strong>. Tryk på valget ud for
        <strong>Send live</strong>, hvor der står <strong>Nej</strong>, og vælg <strong>Opret ny livesending …</strong>.</p>
    ') ?>

    <?= vj_trin('Udfyld livesendingen', '
        <ul>
            <li><strong>Titel:</strong> det, familien ser øverst på livesiden, fx <em>Kajaktur på Horsens Fjord</em>.</li>
            <li><strong>Beskrivelse:</strong> gerne et par ord om turen.</li>
            <li><strong>Aktivitet:</strong> her <strong>Kajak</strong>.</li>
            <li><strong>Forsinkelse:</strong> lad den stå på <strong>Ingen</strong>. Læs mere om forsinkelse nedenfor.</li>
            <li><strong>Hvem kan se livesiden?</strong> Vælg <strong>Kun personer med linket</strong>.</li>
        </ul>
        <p>Tryk på <strong class="knap">Gem</strong>. Du kommer tilbage til appen, og livesendingen er valgt.</p>
    ', vj_billede('web-live-ny.webp', 'Dialogen Ny livesending med Kun personer med linket valgt og knappen Gem')) ?>

    <?= vj_trin('Send linket til familien', '
        <p>Under <strong>Send live til</strong> står nu linket til livesiden. Tryk på
        <strong>del-knappen</strong> til højre for det, og vælg, hvordan du vil sende linket, fx Messenger eller SMS.</p>
        <p>Du kan godt sende linket allerede nu. Indtil du starter, står der bare <strong>Ikke startet endnu</strong>
        på livesiden.</p>
        <p>Linket findes også i kontrolpanelet på mitspor.dk under fanen <strong>Live</strong>.</p>
    ', vj_billede('app-live-del.webp', 'Optageren med linket til livesiden og del-knappen fremhævet')) ?>

    </div>

    <h2>På turen</h2>

    <div class="vj-trinliste">

    <?= vj_trin('Start optagelsen med livesendingen valgt', '
        <p>Åbn optageren. Ud for <strong>Send live til</strong> står din livesending allerede. Står der
        <strong>Nej</strong>, så tryk og vælg den.</p>
        <p>Tryk på <strong class="knap">Start optagelse</strong>, præcis som på en almindelig tur.</p>
    ', vj_billede('app-live-valg.webp', 'Optageren med Send live til Kajaktur på Horsens Fjord valgt og knappen Start optagelse fremhævet')) ?>

    <?= vj_trin('Pak telefonen væk', '
        <p>Under uret står der nu <strong>Sender live til …</strong> og hvornår appen sidst sendte din position.
        Den sender ca. hvert minut, også når skærmen er slukket.</p>
        <p>Mens turen sendes live, bliver du i optageren. Du kan ikke åbne mitspor.dk i appen imens, for så ville
        afsendelsen holde pause.</p>
        <p>Du kan indsætte waypoints, holde pause og stoppe, som du plejer.</p>
    ', vj_billede('app-live-koerer.webp', 'Optageren under en livesending med teksten Sender live til Kajaktur på Horsens Fjord')) ?>

    <?= vj_trin('Det ser familien', '
        <p>Når familien åbner linket, ser de et kort med din tur indtil nu. Den røde prik er dér, hvor du er.
        Øverst står <strong>Live nu</strong> og hvornår din position sidst blev opdateret.</p>
        <p>Siden opdaterer sig selv hvert halve minut, så de kan bare lade den være åben.</p>
    ', vj_billede('web-livesiden.webp', 'Livesiden med kortet over fjorden, den røde streg og teksten Live nu', 'Livesiden, som familien ser den.')) ?>

    <?= vj_trin('Stop, når du er i land', '
        <p>Tryk på <strong class="knap">Stop og gem</strong>. Appen sender det sidste og uploader turen automatisk
        til din konto. Du skal ikke selv trykke på Upload.</p>
        <p>Livesiden viser nu den færdige tur.</p>
    ') ?>

    </div>

    <?= vj_godt('Flere dage i træk', '
        <p>Skal du af sted flere dage, bruger du den samme livesending hver dag. Hver optagelse bliver en
        <strong>etape</strong>, og familien ser alle etaperne på den samme liveside med det samme link.
        Etaperne samles også som én tur på din konto.</p>
        <p>Når hele turen er slut, åbner du livesendingen i kontrolpanelet og trykker på
        <strong>Afslut livesendingen</strong>.</p>
    ') ?>

    <?= vj_godt('Forsinkelse', '
        <p>Vil du ikke have, at andre kan se præcis, hvor du er lige nu, kan du vælge en
        <strong>forsinkelse</strong>, fx 30 minutter. Så ser familien først din position en halv time efter.
        Du ændrer det med <strong>Rediger livesending</strong> i kontrolpanelet.</p>
    ') ?>

    <?= vj_godt('Hvem kan se livesiden?', '
        <p>Med <strong>Kun personer med linket</strong> kan kun dem, du sender linket til, se livesiden, og den
        kommer ikke frem i søgninger. Har linket fået for bred udbredelse, så tryk på
        <strong>Lav nyt link</strong> i kontrolpanelet. Så holder det gamle op med at virke.</p>
        <p>De færdige etaper er private ture på din konto, men dem, der kan se livesiden, kan også åbne
        etaperne derfra.</p>
    ') ?>
<?php
vejledning_slut('foelg-med');
