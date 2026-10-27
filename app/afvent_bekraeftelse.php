<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/app_login.php';

/*
 * Login efter bekræftelse, når kontoen er oprettet i appens WebView.
 *
 * Mailens link åbnes i telefonens browser, ikke i appen. Derfor husker
 * WebView'ets session den nye konto, og siden "Tjek din indbakke" spørger
 * med jævne mellemrum (register-status.php). Er e-mailen bekræftet, logges
 * sessionen ind, og appens hash (par) kobles til kontoen, så appen også
 * logges ind (se app/app_login.php).
 *
 * Kun den session, der oprettede kontoen med adgangskoden, kan logges ind
 * sådan, og kun så længe linket gælder, og adgangskoden er uændret.
 */

/**
 * Husk den nye konto i sessionen. Kaldes af register.php efter oprettelsen.
 */
function afvent_bekraeftelse_start(int $userId, string $passwordHash): void
{
    $_SESSION['afventer_bekraeftelse'] = [
        'user_id'  => $userId,
        'pw'       => hash('sha256', $passwordHash),
        'udloeber' => time() + EMAIL_VERIFICATION_TTL_HOURS * 3600,
    ];
}

/**
 * Er kontoen bekræftet? Returnerer 'logget_ind', 'venter' eller 'ingen'
 * (intet at vente på, udløbet eller adgangskoden er skiftet).
 */
function afvent_bekraeftelse_tjek(): string
{
    $afventer = $_SESSION['afventer_bekraeftelse'] ?? null;
    if (!is_array($afventer)) {
        return 'ingen';
    }

    if ((int) $afventer['udloeber'] < time()) {
        unset($_SESSION['afventer_bekraeftelse']);
        return 'ingen';
    }

    $pdo = get_db();
    $stmt = $pdo->prepare(
        'SELECT password_hash, status, email_verified_at FROM users WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => (int) $afventer['user_id']]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active'
        || !hash_equals((string) $afventer['pw'], hash('sha256', $user['password_hash']))) {
        unset($_SESSION['afventer_bekraeftelse']);
        return 'ingen';
    }

    if ($user['email_verified_at'] === null) {
        return 'venter';
    }

    unset($_SESSION['afventer_bekraeftelse']);

    // Forny session-ID ved login for at undgå session fixation.
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $afventer['user_id'];

    $pairHash = app_ua_param('par');
    if ($pairHash !== null) {
        app_login_pair($pdo, (int) $afventer['user_id'], $pairHash);
    }

    return 'logget_ind';
}
