<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/vejledning.php';

vejledning_start('datafelter', 'Datafelter', 'Vælg, hvad turens side på MitSpor viser, og fjern fx tid eller puls helt fra filen. Trin for trin.');
echo vj_overskrift('datafelter');
?>
    <p class="vj-situation">
        Din tur gemmer mere end selve sporet: hvornår du var hvor, hvor højt du var, og måske din puls. Du vil gerne dele
        turen, men ikke det hele. Måske skal andre ikke se, hvor hurtigt du løb, eller hvornår du plejer at gå hjemmefra.
    </p>

    <h2>To måder at gøre det på</h2>

    <ul>
        <li><strong>Visning af datafelter</strong> i kontrolpanelet <em>skjuler</em> noget på turens side. Filen er
        uændret, og du kan slå det til igen, når du vil.</li>
        <li><strong>Slet datafelter</strong> i editoren <em>fjerner</em> det fra selve filen, også fra den fil, andre kan
        hente. Det kan ikke fortrydes, når du har trykket Gem.</li>
    </ul>

    <h2>Vælg, hvad turens side viser</h2>

    <div class="vj-trinliste">

    <?= vj_trin('Åbn Visning af datafelter', '
        <p>Åbn dit <strong>kontrolpanel</strong>, tryk på turen, og vælg <strong>Visning af datafelter</strong>.</p>
    ', vj_billede('web-datafelter-knap.webp', 'Turens værktøjer i kontrolpanelet med Visning af datafelter fremhævet')) ?>

    <?= vj_trin('Sæt eller fjern flueben', '
        <p>Listen viser kun det, din tur har data til. Har turen fx ingen puls, står Puls ikke på listen.</p>
        <ul>
            <li><strong>Kortet</strong> og <strong>afstanden fra start</strong> vises altid.</li>
            <li><strong>Højdeprofil</strong> og <strong>Højdemeter</strong> bygger på turens højde.</li>
            <li><strong>Tid</strong>, <strong>Hastighed</strong>, <strong>Gennemsnit</strong> og de andre tal om fart
            bygger på turens tid.</li>
            <li><strong>Puls</strong>, <strong>Kadence</strong> og <strong>Watt</strong> er kurver under kortet.</li>
        </ul>
        <p>Nogle visninger, fx <strong>Højdemeter</strong>, er slået fra, indtil du selv sætter flueben. Tryk på
        <strong class="knap">Gem</strong>, når du er færdig.</p>
    ', vj_billede('web-datafelter.webp', 'Dialogen Visning af datafelter med listen over visninger')) ?>

    </div>

    <?= vj_godt('Øvrige felter', '
        <p>Kommer turen fra et ur, kan filen indeholde mere, fx temperatur. Det står under <strong>Øvrige felter</strong>
        nederst i dialogen. Sætter du flueben, vises det som et tal på turens side.</p>
    ') ?>

    <h2>Fjern data fra filen</h2>

    <div class="vj-trinliste">

    <?= vj_trin('Åbn Slet datafelter i editoren', '
        <p>Åbn turen i editoren, tryk på menuen (☰) øverst til højre, og vælg <strong>Slet datafelter</strong>.</p>
    ', vj_billede('web-slet-datafelter-menu.webp', 'Editorens menu med Slet datafelter fremhævet')) ?>

    <?= vj_trin('Tryk Slet ud for det, der skal væk', '
        <p>Listen viser, hvad filen indeholder ud over selve sporet, fx <strong>Højde</strong>, <strong>Tid</strong> og
        <strong>Puls</strong>. Tryk på <strong class="knap">Slet</strong> ud for det, du vil fjerne.</p>
        <p>MitSpor spørger, før noget forsvinder, og fortæller, hvilke visninger turens side mister. Sletter du
        <strong>Tid</strong>, forsvinder fx også hastighed og gennemsnit.</p>
    ', vj_billede('web-slet-datafelter.webp', 'Dialogen Slet datafelter med Højde, Tid og Puls og en Slet-knap ud for hver')) ?>

    <?= vj_trin('Tryk Gem', '
        <p>Endnu er intet ændret. Fortryder du, så tryk på fortryd-pilen. Er du sikker, så tryk på den grønne
        <strong class="knap">Gem</strong>-knap øverst. Først nu er det væk fra filen, og det kan ikke hentes tilbage.</p>
    ') ?>

    </div>

    <h2>Hvornår skal jeg bruge hvad?</h2>

    <ul>
        <li><strong>Andre skal bare ikke se din puls eller fart:</strong> Visning af datafelter er nok, hvis du ikke
        tillader, at andre henter turen. Tillader du det, er dataene stadig i filen.</li>
        <li><strong>Ingen skal kunne se, hvornår du var der:</strong> Slet <strong>Tid</strong>. Tidspunkterne kan vise,
        hvornår du plejer at være hjemmefra.</li>
        <li><strong>Højden ser forkert ud:</strong> Slet den ikke. Vælg i stedet <strong>Rediger højdedata</strong> i
        editorens menu, og tryk på <strong>Hent fra internettet</strong>. Så får turen højder fra et kort, der ofte er
        mere præcist end telefonens.</li>
    </ul>

    <?= vj_godt('Om andre må hente turen', '
        <p>Om andre må hente turen som fil, vælger du i <strong>Rediger metadata</strong>. Se
        <a href="/vejledning/del-turen.php">Del turen med vennerne</a>.</p>
    ') ?>
<?php
vejledning_slut('datafelter');
