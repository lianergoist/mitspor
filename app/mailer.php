<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Sender en e-mail-verifikationsmail med et link, brugeren skal klikke på.
 *
 * @param string $toEmail       Modtagerens e-mailadresse
 * @param string $rawToken      Det UKRYPTEREDE token (hashen gemmes i DB, ikke denne)
 * @return bool                 true hvis mail() rapporterede succes
 */
function send_verification_email(string $toEmail, string $rawToken): bool
{
    $verifyUrl = APP_BASE_URL . '/verify-email.php?token=' . urlencode($rawToken);

    $subject = 'Bekræft din e-mailadresse';

    $message  = "Hej,\r\n\r\n";
    $message .= "Tak for din oprettelse. Bekræft venligst din e-mailadresse ved at klikke på linket herunder:\r\n\r\n";
    $message .= $verifyUrl . "\r\n\r\n";
    $message .= 'Linket udløber om ' . EMAIL_VERIFICATION_TTL_HOURS . " timer.\r\n\r\n";
    $message .= "Har du ikke oprettet en konto, kan du roligt ignorere denne mail.\r\n";

    $headers = [
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_ADDRESS . '>',
        'Content-Type: text/plain; charset=UTF-8',
    ];

    return mail(
        $toEmail,
        $subject,
        $message,
        implode("\r\n", $headers),
        '-f' . MAIL_ENVELOPE_SENDER
    );
}

/**
 * Sender en mail med link til at nulstille adgangskoden.
 */
function send_password_reset_email(string $toEmail, string $rawToken): bool
{
    $resetUrl = APP_BASE_URL . '/reset-password.php?token=' . urlencode($rawToken);

    $subject = 'Nulstil din adgangskode';

    $message  = "Hej,\r\n\r\n";
    $message .= "Du (eller nogen på dine vegne) har bedt om at nulstille adgangskoden til din konto.\r\n\r\n";
    $message .= "Klik på linket herunder for at vælge en ny adgangskode:\r\n\r\n";
    $message .= $resetUrl . "\r\n\r\n";
    $message .= 'Linket udløber om ' . PASSWORD_RESET_TTL_HOURS . " time(r).\r\n\r\n";
    $message .= "Har du ikke bedt om dette, kan du roligt ignorere denne mail — din adgangskode er ikke ændret.\r\n";

    $headers = [
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_ADDRESS . '>',
        'Content-Type: text/plain; charset=UTF-8',
    ];

    return mail(
        $toEmail,
        $subject,
        $message,
        implode("\r\n", $headers),
        '-f' . MAIL_ENVELOPE_SENDER
    );
}

/**
 * Sender en bekræftelsesmail til den NYE e-mailadresse ved et
 * ønsket e-mailskift.
 */
function send_email_change_confirmation(string $newEmail, string $rawToken): bool
{
    $confirmUrl = APP_BASE_URL . '/confirm-email-change.php?token=' . urlencode($rawToken);

    $subject = 'Bekræft din nye e-mailadresse';

    $message  = "Hej,\r\n\r\n";
    $message .= "Du (eller nogen på dine vegne) har bedt om at skifte e-mailadressen på din konto til denne adresse.\r\n\r\n";
    $message .= "Klik på linket herunder for at bekræfte skiftet:\r\n\r\n";
    $message .= $confirmUrl . "\r\n\r\n";
    $message .= 'Linket udløber om ' . EMAIL_CHANGE_TTL_HOURS . " timer.\r\n\r\n";
    $message .= "Har du ikke bedt om dette, kan du roligt ignorere denne mail — din e-mailadresse ændres ikke, før den er bekræftet.\r\n";

    $headers = [
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_ADDRESS . '>',
        'Content-Type: text/plain; charset=UTF-8',
    ];

    return mail(
        $newEmail,
        $subject,
        $message,
        implode("\r\n", $headers),
        '-f' . MAIL_ENVELOPE_SENDER
    );
}

/**
 * Sender en informationsmail til den GAMLE e-mailadresse, så
 * kontoens reelle ejer bliver advaret, hvis nogen andre skulle
 * forsøge at overtage kontoen ved at skifte e-mailen.
 */
function send_email_change_notice(string $oldEmail, string $newEmail): bool
{
    $subject = 'Der er anmodet om skift af e-mailadresse';

    $message  = "Hej,\r\n\r\n";
    $message .= "Der er anmodet om at skifte e-mailadressen på din konto til:\r\n";
    $message .= $newEmail . "\r\n\r\n";
    $message .= "Skiftet træder først i kraft, når den nye adresse bekræftes.\r\n\r\n";
    $message .= "Har du ikke selv bedt om dette, bør du skifte din adgangskode med det samme, da nogen med adgang til din konto har forsøgt at ændre e-mailen.\r\n";

    $headers = [
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_ADDRESS . '>',
        'Content-Type: text/plain; charset=UTF-8',
    ];

    return mail(
        $oldEmail,
        $subject,
        $message,
        implode("\r\n", $headers),
        '-f' . MAIL_ENVELOPE_SENDER
    );
}
