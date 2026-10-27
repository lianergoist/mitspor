<?php
declare(strict_types=1);

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/afvent_bekraeftelse.php';

/**
 * Dialogerne til log ind, opret konto, glemt adgangskode og ny adgangskode.
 * Bruges af forsiden (index.php) og rutesiden (route-view.php) — kun til
 * besøgende, der ikke er logget ind. Adfærden ligger i
 * public/assets/auth-dialogs.js (MitSporAuth.init()).
 */
function render_auth_dialogs(): void
{
    $csrf = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
    ?>
<dialog id="login-dialog" class="modal-dialog">
    <button type="button" id="login-dialog-close" class="modal-close" aria-label="Luk">&times;</button>
    <h2>Log ind</h2>

    <div id="login-errors" class="modal-errors" hidden></div>
    <p id="login-success-note" hidden></p>

    <form id="login-form">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <label>
            E-mail
            <input type="email" name="email" required autocomplete="username">
        </label>

        <label>
            Adgangskode
            <input type="password" name="password" required autocomplete="current-password">
        </label>

        <button type="submit" class="btn btn-primary">Log ind</button>
    </form>

    <p style="margin-top: 10px; font-size: 13px;">
        <button type="button" id="link-open-forgot" class="link-btn">Glemt adgangskode?</button>
        &middot;
        <button type="button" id="link-open-register" class="link-btn">Opret konto</button>
    </p>
</dialog>

<dialog id="forgot-password-dialog" class="modal-dialog">
    <button type="button" id="forgot-password-dialog-close" class="modal-close" aria-label="Luk">&times;</button>
    <h2>Glemt adgangskode</h2>

    <div id="forgot-password-errors" class="modal-errors" hidden></div>

    <div id="forgot-password-success" hidden>
        <p><strong>Tjek din indbakke</strong></p>
        <p>Hvis e-mailadressen findes i vores system, har vi sendt et link til at nulstille adgangskoden.</p>
    </div>

    <form id="forgot-password-form">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <label>
            E-mail
            <input type="email" name="email" required autocomplete="username">
        </label>

        <button type="submit" class="btn btn-primary">Send nulstillingslink</button>
    </form>
</dialog>

<dialog id="reset-password-dialog" class="modal-dialog">
    <button type="button" id="reset-password-dialog-close" class="modal-close" aria-label="Luk">&times;</button>
    <h2>Vælg ny adgangskode</h2>

    <div id="reset-password-errors" class="modal-errors" hidden></div>

    <form id="reset-password-form">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="token" id="reset-password-token" value="">

        <label>
            Ny adgangskode (mindst 10 tegn)
            <input type="password" name="password" required minlength="10" autocomplete="new-password">
        </label>

        <label>
            Gentag ny adgangskode
            <input type="password" name="password_confirm" required minlength="10" autocomplete="new-password">
        </label>

        <button type="submit" class="btn btn-primary">Gem ny adgangskode</button>
    </form>
</dialog>

<dialog id="register-dialog" class="modal-dialog">
    <button type="button" id="register-dialog-close" class="modal-close" aria-label="Luk">&times;</button>
    <h2>Opret konto</h2>

    <div id="register-errors" class="modal-errors" hidden></div>

    <div id="register-success" hidden>
        <p><strong>Tjek din indbakke</strong></p>
        <p>
            Vi har sendt en bekræftelsesmail til den angivne e-mailadresse.
            Klik på linket i mailen for at aktivere din konto.
        </p>
        <?php render_afvent_bekraeftelse(false); ?>
    </div>

    <form id="register-form">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <label>
            E-mail
            <input type="email" name="email" required autocomplete="username">
        </label>

        <label>
            Adgangskode (mindst 10 tegn)
            <input type="password" name="password" required minlength="10" autocomplete="new-password">
        </label>

        <label>
            Vis-navn
            <input type="text" name="display_name" required>
        </label>

        <label class="checkbox-field">
            <input type="checkbox" name="accept_policy" required>
            Jeg accepterer <a href="/privatlivspolitik.php" target="_blank">privatlivspolitikken</a>
        </label>

        <button type="submit" class="btn btn-primary">Opret konto</button>
    </form>

    <p style="margin-top: 10px; font-size: 13px;">
        Har du allerede en konto?
        <button type="button" id="link-open-login" class="link-btn">Log ind</button>
    </p>
</dialog>
    <?php
}
