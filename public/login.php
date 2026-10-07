<?php
declare(strict_types=1);

// login.php er ikke længere en selvstændig side — login sker nu i en
// pop-up-boks på forsiden. Denne fil eksisterer kun, så alle
// EKSISTERENDE henvisninger til "login.php" (require_login() m.fl.)
// fortsat virker, uden at hver enkelt skulle rettes.

$query = $_GET;
$query['showLogin'] = '1';

header('Location: index.php?' . http_build_query($query));
