<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/vejledning.php';

vejledning_start('naar-noget-driller', 'Når noget driller', 'Svar på det, der oftest går galt med MitSpor: GPS, optagelser, upload, pulsbælte, login og deling.');
echo vj_overskrift('naar-noget-driller');
?>
    <p class="vj-situation">
        Noget virker ikke, som du havde regnet med. Her er svar på det, der oftest driller. Finder du ikke svaret, så
        skriv til <a href="mailto:info@mitspor.dk">info@mitspor.dk</a>.
    </p>

    <h2>Når du optager</h2>

    <section class="vj-trin">
        <div class="vj-trin-tekst">
            <h3 class="vj-spm">Der står "Venter på GPS-signal"</h3>
            <p>Telefonen har endnu ikke fundet ud af, hvor du er. Gå udenfor, gerne et sted med frit udsyn til himlen og
            væk fra høje huse, og vent et minut. Indendørs finder GPS'en sjældent et præcist signal.</p>
            <p>Nogle tablets, fx iPads uden mobildata, har ingen GPS og kan ikke optage ture.</p>
        </div>
        <?= vj_billede('app-optag-venter.webp', 'Optageren venter på GPS-signal') ?>
    </section>

    <h3 class="vj-spm">Der står "Ingen adgang til placering"</h3>
    <p>MitSpor har ikke fået lov til at bruge din placering. Appen tilbyder at åbne telefonens indstillinger. Giv lov til
    placering, mens appen bruges, og sørg for, at <strong>præcis placering</strong> er slået til. Se
    <a href="/vejledning/foerste-tur.php">Din første tur</a>.</p>

    <h3 class="vj-spm">Turen har huller eller stoppede, mens skærmen var slukket</h3>
    <p>Nogle Android-telefoner lukker apps for at spare strøm, også når de optager. Find MitSpor under
    <strong>Apps</strong> i telefonens indstillinger, vælg <strong>Batteri</strong>, og vælg den indstilling, der ikke
    begrænser appen, fx <em>Ubegrænset</em> eller <em>Ingen begrænsninger</em>. Navnene er lidt forskellige fra telefon
    til telefon.</p>
    <p>Kan du ikke se notifikationen "MitSpor optager din tur", mens du optager, så tjek også, at MitSpor må sende
    notifikationer.</p>

    <h3 class="vj-spm">Telefonen løb tør for strøm midt på turen</h3>
    <p>Turen er ikke tabt. Næste gang du åbner optageren, kan du vælge <strong>Fortsæt optagelsen</strong> eller
    <strong>Afslut og gem</strong>. Se <a href="/vejledning/uden-net.php">På tur uden net</a>.</p>

    <h3 class="vj-spm">Optagelsen stoppede af sig selv på en meget lang tur</h3>
    <p>En tur kan højst fylde 10 MB. Bliver den så lang, stopper appen og gemmer turen, og du får besked. Start bare en
    ny optagelse og gå videre. Bagefter kan du samle de to ture i en gruppe. Se
    <a href="/vejledning/ferie.php">Grupper og etaper</a>.</p>

    <h3 class="vj-spm">Turen har et mærkeligt hop, eller bilturen hjem kom med</h3>
    <p>Markér stykket i editoren, og slet det. Se <a href="/vejledning/glemte-stop.php">Rediger en optagelse</a>.</p>

    <h2>Når turen skal op på mitspor.dk</h2>

    <h3 class="vj-spm">Der står "Venter på upload"</h3>
    <p>Telefonen er ikke på nettet lige nu. Appen sender turen af sig selv, når der er net, eller næste gang du åbner
    appen. Står der <strong>Log ind for at sende turen</strong>, skal du først logge ind.</p>

    <h3 class="vj-spm">Der står "Upload mislykkedes"</h3>
    <p>Appen prøver igen af sig selv lidt senere. Du kan også trykke på <strong>Prøv igen</strong>. Bliver det ved, så
    skriv til os, og fortæl, hvad der står efter "Upload mislykkedes".</p>

    <h3 class="vj-spm">Jeg kan ikke åbne mitspor.dk i appen</h3>
    <p>Mitspor.dk kræver net. Sender du live, bliver du i optageren, til du holder pause eller stopper, for ellers ville
    afsendelsen holde pause.</p>

    <h2>Pulsbælte og andre målere</h2>

    <h3 class="vj-spm">Appen finder ikke mit pulsbælte</h3>
    <ul>
        <li>Bæltet tænder først, når det sidder på kroppen. Gør felterne på indersiden lidt fugtige.</li>
        <li>Tjek, at Bluetooth er slået til, og at MitSpor må bruge Bluetooth (på Android: <em>enheder i nærheden</em>).</li>
        <li>Nogle bælter kan kun være forbundet til én ting ad gangen. Er det forbundet til dit ur eller en anden app,
        så sluk den først.</li>
        <li>Tryk på <strong>Søg igen</strong>.</li>
    </ul>

    <h2>Konto og login</h2>

    <h3 class="vj-spm">Jeg har glemt min adgangskode</h3>
    <p>Tryk på <strong>Log ind</strong> og derefter på <strong>Glemt adgangskode?</strong>. Skriv din e-mail, så får du en
    mail med et link, hvor du vælger en ny adgangskode.</p>

    <h3 class="vj-spm">Jeg har ikke fået mailen</h3>
    <p>Kig i mappen med uønsket post (spam), og vent et par minutter. Er den der stadig ikke, så tjek, at du har skrevet
    den rigtige e-mailadresse, og prøv igen.</p>

    <h3 class="vj-spm">Jeg vil skifte e-mail, navn eller adgangskode</h3>
    <p>Åbn dit kontrolpanel, tryk på menuen (☰), og vælg <strong>Profil og konto</strong>.</p>

    <h2>Deling</h2>

    <h3 class="vj-spm">Et link til en tur virker ikke</h3>
    <p>Står der <strong>Ingen adgang</strong> eller <strong>Linket virker ikke</strong>, er turen privat, og linket er
    slettet eller udskiftet af ejeren. Bed om et nyt link. Se
    <a href="/vejledning/del-turen.php">Del turen med vennerne</a> og <a href="/vejledning/alle-ture.php">Del alle dine ruter</a>.</p>

    <h3 class="vj-spm">Andre kan ikke se min gruppe</h3>
    <p>Andre ser kun de etaper, der er offentlige. Se <a href="/vejledning/ferie.php">Grupper og etaper</a>.</p>

    <h2>Din konto og dine data</h2>

    <p>Under <strong>Profil og konto</strong> i kontrolpanelets menu (☰) kan du:</p>
    <ul>
        <li><strong>Download alle mine data:</strong> få alle dine ture, grupper, billeder og livesendinger i én samlet fil.
        Gør det gerne, før du sletter kontoen.</li>
        <li><strong>Slet min konto:</strong> slette kontoen med alle dine ture og billeder. Du skal skrive "slet min konto" og
        din adgangskode for at bekræfte. Det kan ikke fortrydes.</li>
    </ul>
    <p>Hvad MitSpor gemmer, og hvorfor, står i <a href="/privatlivspolitik.php">privatlivspolitikken</a>.</p>
<?php
vejledning_slut('naar-noget-driller');
