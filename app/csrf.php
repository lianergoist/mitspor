<?php
declare(strict_types=1);

/**
 * Returnerer det aktuelle CSRF-token for sessionen. Genererer et nyt,
 * hvis der ikke findes et endnu.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Validér et indsendt CSRF-token mod sessionens token.
 * Bruger hash_equals() for at undgå timing-angreb.
 */
function csrf_validate(?string $submittedToken): bool
{
    if (empty($_SESSION['csrf_token']) || $submittedToken === null) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $submittedToken);
}
