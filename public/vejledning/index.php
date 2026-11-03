<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/vejledning.php';

vejledning_start('', 'Vejledning', 'Sådan bruger du MitSpor: optag dine ture, se dem på et kort, og del dem med andre. Forklaret trin for trin.');
?>
    <h1>Vejledning til MitSpor</h1>

    <p class="vj-intro">
        Med MitSpor kan du optage dine ture med telefonen, se dem på et kort og dele dem med
        familie og venner. Det gælder både løbeturen i skoven, cykelturen og kajakturen på fjorden.
    </p>

    <p>
        Vejledningen tager udgangspunkt i situationer, du måske kender. Du lærer det, du har
        brug for, undervejs, og du behøver ikke vide noget om GPS eller kort på forhånd.
    </p>

    <div class="vj-bruge">
        <p>Det skal du bruge</p>
        <ul>
            <li>En mobiltelefon med appen MitSpor. Den er gratis i <a href="<?= VJ_APP_STORE ?>">App Store</a>
            og <a href="<?= VJ_GOOGLE_PLAY ?>">Google Play</a>.</li>
            <li>En konto på MitSpor. Den er også gratis, og du opretter den på et par minutter.</li>
            <li>Eventuelt en computer. Det er ikke nødvendigt, men de større ændringer af en tur er nemmest at lave på en stor skærm.</li>
        </ul>
    </div>

    <h2>Sådan læser du vejledningen</h2>
    <p>
        Begynd med <a href="/vejledning/kom-i-gang.php">Kom i gang</a>. Derefter kan du læse kapitlerne i
        rækkefølge eller springe direkte til det emne, der passer dig. Ved hvert kapitel står der, om
        det foregår på mobilen, på computeren eller begge steder.
    </p>

    <h2>Kapitler</h2>
    <ol class="vj-kapitler">
        <?php foreach (vejledning_kapitler() as $slug => $k): ?>
            <li>
                <?php if ($k['klar']): ?>
                <a href="/vejledning/<?= vj_h($slug) ?>.php">
                <?php else: ?>
                <div class="vj-snart">
                <?php endif; ?>
                    <span class="vj-kap-titel"><?= vj_h($k['titel']) ?></span>
                    <span class="vj-kap-kort"><?= vj_h($k['kort']) ?></span>
                    <span class="vj-kap-maerker">
                        <?= vj_enhed($k['enhed']) ?>
                        <?php if (!$k['klar']): ?><span class="vj-snart-tekst">Kommer snart</span><?php endif; ?>
                    </span>
                <?php if ($k['klar']): ?>
                </a>
                <?php else: ?>
                </div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
<?php
vejledning_slut('');
