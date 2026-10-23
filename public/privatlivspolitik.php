<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="/grafik/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/grafik/favicon-32.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privatlivspolitik – MitSpor</title>
    <meta name="description" content="Sådan behandler MitSpor dine oplysninger: hvad vi gemmer, hvorfor, hvor længe, og hvordan du selv kan downloade eller slette dine data.">
    <link rel="canonical" href="<?= APP_BASE_URL ?>/privatlivspolitik.php">

    <link rel="stylesheet" href="assets/style.css?v=30">
    <style>
        .policy { max-width: 780px; }
        .policy h1 { margin: 0 0 4px; font-size: 26px; }
        .policy h2 { margin: 30px 0 8px; font-size: 18px; }
        .policy h3 { margin: 18px 0 4px; font-size: 15px; }
        .policy p, .policy li { font-size: 15px; }
        .policy ul { padding-left: 22px; margin: 6px 0 12px; }
        .policy li { margin-bottom: 4px; }
        .policy .updated { color: var(--text-muted); font-size: 13px; margin: 0 0 18px; }
        .policy .summary {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: var(--radius);
            padding: 14px 18px;
            margin: 16px 0 8px;
        }
        .policy .summary p { margin: 0 0 6px; font-weight: 600; }
        .policy .summary ul { margin: 0; }
    </style>
</head>
<body>

<header class="topbar">
    <a href="index.php" class="brand brand-navn"><span class="brand-text">MitSpor</span></a>
    <a href="index.php" class="btn">Til forsiden</a>
</header>

<main class="container policy">
<div class="card">

<article id="privatlivspolitik">

    <h1>Privatlivspolitik</h1>
    <p class="updated">Sidst opdateret: 19. oktober 2026</p>

    <div class="summary">
        <p>Kort fortalt</p>
        <ul>
            <li>MitSpor er gratis, og vi tjener ikke penge på dine oplysninger.</li>
            <li>Vi gemmer kun det, der skal til: din e-mail, dit navn, en krypteret adgangskode og de ruter og billeder, du selv lægger op.</li>
            <li>Appen optager kun din placering, når du selv har trykket Start, og turen bliver på din telefon, til du selv vælger at uploade den. Kun hvis du selv vælger at sende turen live, sendes din placering løbende, og så kun til den livesending, du har valgt.</li>
            <li>Ingen reklamer, ingen statistik- eller sporingsværktøjer og ingen tredjepartscookies.</li>
            <li>Du bestemmer selv, hvem der kan se dine ruter, og hvilke data i en fil andre kan hente.</li>
            <li>Du kan altid downloade alle dine data og slette både ruter og hele din konto.</li>
            <li>Bruger du ikke din konto i et år, sletter vi den. Du får en mail 30 og 7 dage før.</li>
        </ul>
    </div>

    <h2>1. Hvem er ansvarlig for dine oplysninger?</h2>
    <p>
        MitSpor drives af Thomas Jensen, Griffenfeldtsgade 7, 8700 Horsens (”vi”).
        Vi er dataansvarlige for de oplysninger, der beskrives her.
        Har du spørgsmål eller vil du bruge dine rettigheder, kan du skrive til
        <a href="mailto:info@mitspor.dk">info@mitspor.dk</a>.
    </p>

    <h2>2. Hvilke oplysninger vi behandler, og hvorfor</h2>

    <h3>Din konto</h3>
    <ul>
        <li>E-mailadresse, visningsnavn og adgangskode. Adgangskoden gemmes kun som en krypteret kode (hash), så hverken vi eller andre kan læse den.</li>
        <li>Tidspunktet for oprettelsen, for bekræftelsen af din e-mail og for din kvittering på advarslen om offentlige ruter (se afsnit 4).</li>
    </ul>
    <p><em>Formål:</em> at give dig adgang til tjenesten. <em>Grundlag:</em> aftalen mellem dig og os om at bruge MitSpor (GDPR art. 6, stk. 1, litra b).</p>

    <h3>Dine ruter og spor</h3>
    <ul>
        <li>GPX-filen med koordinater. Afhængigt af din enhed kan den også indeholde tid, højde, puls, kadence, effekt (watt) og andre målinger.</li>
        <li>Titel, beskrivelse, aktivitetstype, om ruten er privat eller offentlig, og om navnet skal skjules.</li>
        <li>Beregnede oplysninger som længde, højdemeter samt start- og slutposition.</li>
        <li>Waypoints med tekst og henvisninger til billeder.</li>
    </ul>
    <p>
        <em>Formål:</em> at gemme, vise og dele dine ruter, som du selv vælger. <em>Grundlag:</em> aftalen om at bruge tjenesten.
        Gør du en rute offentlig, sker det efter dit eget valg og samtykke (art. 6, stk. 1, litra a).
    </p>
    <p>
        <strong>Bemærk om pulsdata:</strong> Puls kan regnes som en helbredsoplysning. Hvis du vælger at uploade eller vise pulsdata,
        sker det efter dit udtrykkelige samtykke (art. 9, stk. 2, litra a). Du kan til enhver tid fjerne pulsdata (og andre felter) fra filen i kontrolpanelet
        under <em>Slet datafelter</em>.
    </p>

    <h3>Dine billeder</h3>
    <p>
        Billeder, du uploader til dine waypoints på sitet eller fra appen, gemmes i din billedsamling sammen med en lille miniature.
        Når du uploader et billede, fjerner vi automatisk skjulte oplysninger i filen, såsom GPS-position, kameramodel og
        tidspunkt (såkaldte EXIF-data). Selve billedet ændres ikke. Det gælder JPEG-, PNG- og WebP-billeder; GIF-filer indeholder ikke EXIF-data.
    </p>

    <h3>Appen til iPhone og Android</h3>
    <ul>
        <li><strong>Placering:</strong> Appen bruger kun din placering, når du selv har trykket Start, og indtil du trykker Stop. Placeringen bruges også, mens skærmen er slukket eller appen ligger i baggrunden, så hele turen kommer med. På Android vises en notifikation, så længe optagelsen kører. Punkter med dårlig nøjagtighed kasseres.</li>
        <li><strong>Lagring på telefonen:</strong> Turen gemmes løbende i appens egen mappe på telefonen, så den ikke går tabt, hvis appen lukker. Din placering sendes ikke til os under optagelsen, medmindre du har valgt at sende turen live (se <em>Livesending</em> nedenfor).</li>
        <li><strong>Upload:</strong> Først når du trykker Upload på en tur, sendes GPX-filen, titlen, aktivitetstypen og billederne til turens waypoints til os, og ruten oprettes som privat. En tur, der er sendt live, uploades dog automatisk, når du stopper den. Hver optagelse har et tilfældigt id, så den samme tur ikke bliver oprettet to gange, hvis uploaden skal prøves igen.</li>
        <li><strong>Kamera og billeder:</strong> Appen bruger kun kameraet eller dine fotos, når du selv trykker <em>Tag billede</em> eller <em>Vælg billeder</em> ved et waypoint under en optagelse. Appen får kun adgang til de billeder, du selv vælger, ikke til resten af dine fotos. På Android åbnes telefonens egen kamera-app, så MitSpor får ikke selv adgang til kameraet. Billederne gøres mindre og gemmes sammen med turen i appens egen mappe på telefonen. De sendes først til os, når turen uploades, og behandles derefter som andre billeder (se <em>Dine billeder</em>): skjulte oplysninger som GPS-position fjernes, og billederne lægges i din billedsamling og vises ved waypointet på ruten. Hvert billede har et tilfældigt id, så det ikke bliver gemt to gange, hvis uploaden skal prøves igen.</li>
        <li><strong>Login:</strong> Når du logger ind i appen, sendes din e-mail og adgangskode krypteret til os og kontrolleres som ved login på sitet. Appen gemmer ikke din adgangskode. Den får i stedet en adgangsnøgle (token), som gemmes i telefonens sikre nøglering (Keychain på iPhone, Keystore på Android). Hos os gemmes kun en krypteret kode (hash) af nøglen, hvornår den er oprettet og sidst brugt, hvornår den udløber, og om den hører til iPhone- eller Android-appen. Vi gemmer ikke telefonens navn, model eller id.</li>
        <li><strong>Fælles login med sitet:</strong> Appen viser sitet i et indbygget browservindue. For at du ikke skal logge ind to gange, bruges en engangskode, der kun gælder i 60 sekunder og kun kan bruges én gang. Vi gemmer kun en krypteret kode (hash) af den.</li>
        <li><strong>Andet på telefonen:</strong> Listen over aktivitetstyper, den aktivitetstype du sidst valgte, og om introduktionen er vist. Filer, du henter (GPX-filer og dataeksport), lægges midlertidigt i appens cache, så du kan dele eller gemme dem med telefonens del-funktion.</li>
        <li>Appen indeholder ingen reklamer, statistik- eller fejlrapporteringsværktøjer og taler kun med mitspor.dk.</li>
    </ul>
    <p><em>Formål:</em> at du kan optage ture og lægge dem på din konto. <em>Grundlag:</em> aftalen om at bruge tjenesten.</p>

    <h3>Livesending</h3>
    <p>
        Med en livesending kan andre følge din tur på et kort, mens du er undervejs. Du opretter selv livesendingen på sitet og vælger
        i appen, hvilken optagelse der skal sendes live. Der sendes aldrig live, medmindre du selv har valgt det ved start.
    </p>
    <ul>
        <li><strong>Livesendingen:</strong> titel, beskrivelse, aktivitetstype, planlagt start, en evt. plan (en af dine ruter eller grupper), hvem der kan se den, og den valgte forsinkelse.</li>
        <li><strong>Under optagelsen:</strong> Appen sender ca. hvert minut de nye punkter fra turen: position, tidspunkt, højde og GPS'ens nøjagtighed. Vi gemmer også, hvornår hver etape startede og sluttede.</li>
        <li><strong>Når en etape slutter:</strong> Den færdige tur uploades automatisk som en privat rute på din konto. Har livesendingen flere etaper, samles de i en gruppe.</li>
        <li><strong>På telefonen:</strong> Appen gemmer en liste med titlerne på dine åbne livesendinger, så du kan vælge en uden net.</li>
    </ul>
    <p>
        <em>Formål:</em> at andre kan følge din tur, mens den foregår. <em>Grundlag:</em> dit samtykke, når du vælger at sende live (art. 6, stk. 1, litra a).
        Du kan til enhver tid stoppe ved at stoppe optagelsen, afslutte livesendingen eller slette den.
    </p>

    <h3>Sikkerhed og misbrugsbeskyttelse</h3>
    <ul>
        <li>Ved login (også i appen), oprettelse af konto, glemt adgangskode og skift af e-mail noterer vi IP-adresse, tidspunkt og den e-mailadresse, der blev indtastet, så vi kan spærre for gentagne forsøg og misbrug.</li>
        <li>Webserveren fører en teknisk adgangslog (IP-adresse, tidspunkt, hvilken side der blev hentet, og din browsertype) af hensyn til drift og fejlsøgning. Bruger du appen, indeholder browsertypen også en teknisk kode, der forbinder login i appen og på sitet.</li>
    </ul>
    <p><em>Grundlag:</em> vores legitime interesse i at beskytte tjenesten og dens brugere (art. 6, stk. 1, litra f).</p>

    <h3>Anmeldelser</h3>
    <p>
        Anmelder du en rute, gemmer vi din begrundelse og tidspunktet. Administratoren kan se, hvem der har anmeldt (navn og e-mail),
        så anmeldelsen kan behandles. <em>Grundlag:</em> vores legitime interesse i at kunne fjerne ulovligt eller upassende indhold.
    </p>

    <h3>E-mails</h3>
    <p>
        Vi sender kun tekniske mails: bekræftelse af e-mail, nulstilling af adgangskode, bekræftelse eller advarsel ved skift af e-mailadresse og advarsel, før en inaktiv konto slettes (se afsnit 6).
        Vi sender ikke nyhedsbreve eller markedsføring. Mails sendes via internettets almindelige mailsystem og kan derfor passere din mailudbyders servere.
    </p>

    <h2>3. Cookies</h2>
    <p>
        Vi bruger <strong>én teknisk cookie</strong> (en sessionscookie). Den er nødvendig for, at du kan logge ind og for at sikre formularerne mod misbrug.
        Den sættes også, hvis du blot besøger sitet uden at logge ind, og den forsvinder som udgangspunkt, når du lukker browseren eller logger ud.
        Fordi den er strengt nødvendig, kræver den ikke samtykke.
        I appen bruges den samme cookie i det indbyggede browservindue. Den slettes, når du logger ud i appen.
    </p>
    <p>Vi bruger ingen sporings- eller reklamecookies, ingen værktøjer til statistik og ingen tredjeparts-cookies.</p>

    <h2>4. Hvem kan se hvad?</h2>
    <ul>
        <li><strong>Private ruter</strong> kan kun ses af dig og af personer, der får et delingslink fra dig. Alle, der har linket, kan se ruten – del det kun med personer, du stoler på.</li>
        <li><strong>Offentlige ruter</strong> kan ses af alle på internettet og kan blive fundet af søgemaskiner. Vær opmærksom på, at start- og slutpunktet i en offentlig rute kan afsløre, hvor du bor. Derfor beder vi dig bekræfte, at du forstår det, første gang du gør en rute offentlig.</li>
        <li><strong>Dit navn</strong> vises som uploader af offentlige ruter, medmindre du vælger, at ruten skal være anonym.</li>
        <li><strong>Download:</strong> andre kan kun hente GPX-filen, hvis du har tilladt det. Filen indeholder de data, der ligger i den. Vil du ikke dele fx tid eller puls, kan du fjerne dem fra filen i editoren, før du tillader download.</li>
        <li><strong>Livesendinger</strong> kan enten ses af alle, der har linket (det indeholder en hemmelig nøgle), eller af alle på internettet, hvis du gør livesendingen offentlig. Livesiden viser, hvor du er, og hvor du har været på turen. Vælger du en forsinkelse, ser andre først din position så mange minutter efter, og det færdige spor vises først, når forsinkelsen er gået. Du kan selv lave et nyt link, så det gamle holder op med at virke. Livesider bliver ikke vist i søgemaskiner. De færdige etaper er private ruter, men dem, der kan se livesiden, kan også åbne etapernes egne sider derfra. Det sker via et del-link på etapen, som du kan slette under <em>Del-link</em> i kontrolpanelet.</li>
        <li><strong>Billeder</strong> har svære at gætte adresser, men er ikke beskyttet af login. Den, der har adressen til et billede, kan se det – også hvis ruten senere gøres privat.</li>
        <li><strong>Administrator:</strong> Vi kan se anmeldte ruter og anmeldelser. Ud over det bruger vi kun teknisk adgang til data, når det er nødvendigt for drift og sikkerhed.</li>
    </ul>

    <h2>5. Eksterne tjenester</h2>
    <ul>
        <li><strong>Kort:</strong> Kortet kommer fra OpenStreetMap. Kortbillederne hentes af vores server og gemmes midlertidigt hos os, så OpenStreetMap ikke får din IP-adresse eller kan se, hvad du kigger på.</li>
        <li><strong>Højdedata:</strong> Vælger du selv at hente højdedata til en rute, sendes rutens koordinater fra vores server til tjenesten Open Topo Data (api.opentopodata.org), som svarer med højder. Din IP-adresse sendes ikke med. Det sker kun, når du selv trykker på knappen.</li>
        <li>Vi bruger ingen reklamenetværk, statistikværktøjer, sociale plugins, eksterne skrifttyper eller indholdsnetværk (CDN). Alle programbiblioteker ligger på vores egen server.</li>
        <li><strong>Drift:</strong> Sitet og dets data ligger på en server hos ssdvps.dk i Danmark.</li>
    </ul>
    <p>Vi sælger ikke dine oplysninger og giver dem ikke videre til andre til markedsføring.</p>

    <h2>6. Hvor længe gemmer vi dine oplysninger?</h2>
    <ul>
        <li><strong>Konto, ruter og billeder:</strong> så længe du har din konto, og du bruger den. MitSpor er gratis og har begrænset plads, så har du ikke været logget ind på sitet eller brugt appen i et år, sletter vi kontoen med alle ruter og billeder, som hvis du selv havde slettet den. Du får en mail 30 dage og igen 7 dage før, og du kan nå at downloade dine data. Logger du ind inden da, sker der ingenting. Vi sletter først, når begge mails er sendt. Afviser din mailudbyder dem, eller havner de i spam, sletter vi alligevel.</li>
        <li><strong>Konti, hvor e-mailen aldrig er bekræftet:</strong> slettes efter 30 dage uden advarsel.</li>
        <li><strong>Sletter du en rute:</strong> GPX-filen og en evt. gemt original-fil (sikkerhedskopi fra udtynding) slettes straks, og rutens titel, beskrivelse, delingslink og positioner ryddes. Der bevares kun en tom markering, så personer med et gammelt link kan se, at ruten er slettet. Billeder i din billedsamling bliver liggende, til du selv sletter dem under <em>Mine billeder</em> eller sletter din konto.</li>
        <li><strong>Sletter du din konto:</strong> alle dine ruter og billeder (inklusive miniaturer) slettes, og din e-mail, dit navn og din adgangskode fjernes med det samme.</li>
        <li><strong>Livesendinger:</strong> Livesendingen og de punkter, appen har sendt live, gemmes, til du sletter livesendingen eller din konto. De færdige etaper er almindelige ruter og bliver liggende, til du sletter dem.</li>
        <li><strong>Ture på telefonen</strong> (med waypoints og billeder): bliver liggende i appen, også efter upload, til du sletter dem i appen eller afinstallerer appen. Vi kan ikke se eller slette dem.</li>
        <li><strong>Adgangsnøgler til appen:</strong> holder op med at virke, når du logger ud i appen, når du skifter eller nulstiller din adgangskode, og når de ikke har været brugt i 180 dage. Oplysningerne om nøglerne slettes sammen med din konto. Engangskoder til fælles login slettes efter et døgn.</li>
        <li><strong>Sikkerhedsnoter (IP-adresser ved login m.m.):</strong> slettes automatisk efter 30 dage.</li>
        <li><strong>Serverens adgangslog:</strong> 3 måneder.</li>
        <li><strong>Anmeldelser:</strong> så længe det er nødvendigt for at behandle henvendelsen.</li>
        <li><strong>Sikkerhedskopier:</strong> Der tages en daglig sikkerhedskopi af serveren, som gemmes i 3 måneder. Data, du har slettet, kan derfor ligge i sikkerhedskopierne i op til 3 måneder, hvorefter de er væk. Sikkerhedskopierne bruges kun til at genskabe tjenesten efter en fejl.</li>
    </ul>

    <h2>7. Dine rettigheder</h2>
    <p>Du har efter GDPR ret til:</p>
    <ul>
        <li><strong>Indsigt og dataportabilitet:</strong> Under din profil kan du selv hente alle dine data (<em>Download alle mine data</em>) som en ZIP-fil med dine GPX-filer, billeder, livesendinger (med de punkter, appen har sendt live) og oplysninger om din konto.</li>
        <li><strong>Berigtigelse:</strong> Du kan selv rette dit navn og din e-mail i din profil.</li>
        <li><strong>Sletning:</strong> Du kan selv slette enkelte ruter og hele din konto, også fra appen via din profil. Billeder sletter du under <em>Mine billeder</em> i din profil. Et billede, der stadig bruges i en af dine ruter, skal først fjernes fra ruten i editoren.</li>
        <li><strong>Begrænsning og indsigelse:</strong> Du kan bede os begrænse behandlingen eller gøre indsigelse mod den, når den bygger på vores legitime interesse.</li>
        <li><strong>Tilbagekaldelse af samtykke:</strong> Fx ved at gøre en rute privat, fjerne data som puls fra en fil eller stoppe, afslutte eller slette en livesending.</li>
    </ul>
    <p>
        Skriv til <a href="mailto:info@mitspor.dk">info@mitspor.dk</a>, hvis du vil bruge dine rettigheder. Vi svarer senest inden for en måned.
        Er du utilfreds med, hvordan vi behandler dine oplysninger, kan du klage til
        <a href="https://www.datatilsynet.dk" target="_blank" rel="noopener">Datatilsynet</a>.
    </p>

    <h2>8. Sikkerhed</h2>
    <p>
        Sitet og appen bruger krypteret forbindelse (HTTPS). Adgangskoder og appens adgangsnøgler gemmes kun som hash. Gentagne mislykkede login-forsøg bremses,
        og private ruter kan ikke ses uden login eller delingslink. Ingen løsning er dog fuldstændig sikker,
        så vælg en adgangskode, du ikke bruger andre steder.
    </p>

    <h2>9. Ændringer</h2>
    <p>
        Vi opdaterer politikken, når tjenesten eller reglerne ændrer sig. Datoen øverst viser, hvornår den sidst er ændret.
    </p>

</article>

</div>
</main>

</body>
</html>
