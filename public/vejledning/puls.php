<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/vejledning.php';

vejledning_start('puls', 'Pulsbælte m.m.', 'Brug et pulsbælte med MitSpor-appen, se pulsen undervejs og bagefter, og vælg, hvad andre må se. Trin for trin.');
echo vj_overskrift('puls');
?>
    <p class="vj-situation">
        Du træner til et halvmaraton og løber med pulsbælte. Du vil gerne se pulsen undervejs og bagefter se,
        hvordan den gik op og ned på turen.
    </p>

    <div class="vj-bruge">
        <p>Det skal du bruge</p>
        <ul>
            <li>Et pulsbælte eller en anden pulsmåler med <strong>Bluetooth</strong>.</li>
            <li>Bluetooth slået til på telefonen.</li>
        </ul>
    </div>

    <h2>Første gang: forbind pulsbæltet</h2>

    <div class="vj-trinliste">

    <?= vj_trin('Tag bæltet på', '
        <p>Et pulsbælte tænder først, når det sidder på kroppen. Gør gerne de to felter på indersiden lidt fugtige,
        så måler det med det samme.</p>
    ') ?>

    <?= vj_trin('Vælg Pulsmåler i optagerens menu', '
        <p>Tryk på <strong class="knap">Optag tur</strong>, og tryk på menuen (☰) øverst til højre. Vælg
        <strong>Pulsmåler</strong>.</p>
        <p>Første gang spørger telefonen, om MitSpor må bruge Bluetooth. På Android hedder det
        <em>enheder i nærheden</em>. Tryk på <strong>Tillad</strong>.</p>
    ', vj_billede('app-sensor-menu.webp', 'Optagerens menu med Pulsmåler fremhævet')) ?>

    <?= vj_trin('Vælg dit bælte', '
        <p>Appen leder efter pulsmålere i nærheden. Når dit bælte dukker op, er det markeret. Tryk på
        <strong class="knap">OK</strong>.</p>
        <p>Kommer det ikke frem, så tjek, at bæltet sidder rigtigt, og tryk på <strong>Søg igen</strong>.</p>
        <p>Telefonen husker bæltet. Næste gang forbinder den selv, når du åbner optageren.</p>
    ', vj_billede('app-sensor-dialog.webp', 'Dialogen Pulsmåler med Polar H10 fundet og markeret')) ?>

    </div>

    <h2>På turen</h2>

    <div class="vj-trinliste">

    <?= vj_trin('Tjek pulsen, før du starter', '
        <p>I optageren står pulsen nu ved siden af afstanden. Så kan du se, at bæltet virker, før du løber.</p>
        <p>Under <strong>Vis sensorer</strong> står der <strong>Gem puls fra …</strong> med flueben. Det betyder, at
        pulsen bliver gemt i turen. Fjerner du fluebenet, bliver pulsen hverken vist eller gemt.</p>
        <p>Tryk på <strong class="knap">Start optagelse</strong>.</p>
    ', vj_billede('app-sensor-valg.webp', 'Optageren før start med puls 72 og Gem puls fra Polar H10 afkrydset')) ?>

    <?= vj_trin('Se pulsen undervejs', '
        <p>Mens du løber, viser optageren din puls sammen med tid og afstand.</p>
    ', vj_billede('app-sensor-koerer.webp', 'Optageren under turen med pulsen 152 fremhævet')) ?>

    </div>

    <h2>Bagefter</h2>

    <div class="vj-trinliste">

    <?= vj_trin('Se pulskurven', '
        <p>Upload turen som altid, og åbn turens side. Under kortet er der en kurve. Tryk på fanen
        <strong>Puls</strong>. Så ser du, hvordan pulsen gik op og ned, og over kurven står laveste, gennemsnitlig og
        højeste puls.</p>
        <p>Tryk på ▶ under kortet, så følger en streg i kurven med, mens turen afspilles.</p>
    ', vj_billede('web-puls-graf.webp', 'Turens side med fanen Puls valgt og pulskurven')) ?>

    <?= vj_trin('Vælg, hvad andre må se', '
        <p>Din puls er din egen. Vil du dele turen uden pulsen, så åbn <strong>kontrolpanelet</strong>, tryk på turen,
        og vælg <strong>Visning af datafelter</strong>.</p>
    ', vj_billede('web-datafelter-knap.webp', 'Turens værktøjer i kontrolpanelet med Visning af datafelter fremhævet')) ?>

    <?= vj_trin('Fjern fluebenet ved Puls', '
        <p>Listen viser alt, hvad turens side kan vise. Fjern fluebenet ved <strong>Puls (graf)</strong>, og tryk på
        <strong class="knap">Gem</strong>. Du kan på samme måde skjule fx tid og hastighed.</p>
    ', vj_billede('web-datafelter.webp', 'Dialogen Visning af datafelter med listen over visninger')) ?>

    </div>

    <?= vj_godt('Skjult er ikke slettet', '
        <p>Visning af datafelter bestemmer kun, hvad turens side viser. Har du tilladt, at andre må hente turen som fil,
        er pulsen stadig i filen. Vil du have den helt væk, så åbn turen i editoren, og vælg
        <strong>Slet datafelter</strong> i menuen (☰).</p>
    ') ?>

    <?= vj_godt('Cykel og løb: kadence og watt', '
        <ul>
            <li><strong>Kadence</strong> er, hvor mange gange du træder rundt i pedalerne, eller hvor mange skridt du tager,
            pr. minut. <strong>Watt</strong> er, hvor hårdt du træder.</li>
            <li>Har du en wattmåler, en kadencesensor til cyklen eller en skridtmåler til løb med Bluetooth, så vælg
            <strong>Kadence og watt</strong> i optagerens menu. Det virker ligesom med pulsbæltet.</li>
            <li>Uden måler kan telefonens egen skridttæller give løbekadencen. Sæt flueben ved
            <strong>Gem kadence fra telefonens skridttæller</strong> under Vis sensorer.</li>
        </ul>
    ') ?>

    <?= vj_godt('Nyt bælte?', '
        <p>Vælg <strong>Pulsmåler</strong> i menuen igen, og vælg det nye. Med <strong>Glem måleren</strong> får telefonen
        at vide, at den ikke skal forbinde til det gamle bælte mere.</p>
    ') ?>
<?php
vejledning_slut('puls');
