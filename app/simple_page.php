<?php
declare(strict_types=1);

/**
 * Begyndelsen og slutningen af en enkel side med sitets topbar og ét kort
 * midt på siden (slet konto, bekræft e-mail, ingen adgang).
 */
function simple_page_start(string $title): void
{
    ?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/grafik/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/grafik/favicon-32.png">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> – MitSpor</title>
    <link rel="stylesheet" href="/assets/style.css?v=35">
</head>
<body>

<header class="topbar">
    <a href="/index.php" class="brand brand-navn"><span class="brand-text">MitSpor</span></a>
    <a href="/index.php" class="btn">Til forsiden</a>
</header>

<main class="container simple-page">
    <div class="card">
<?php
}

function simple_page_end(): void
{
    ?>
    </div>
</main>

</body>
</html>
<?php
}

/**
 * En hel side med en overskrift og en kort besked, fx "Ingen adgang".
 * $messageHtml skal allerede være escapet.
 */
function simple_message_page(string $title, string $messageHtml): void
{
    simple_page_start($title);
    echo '        <h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</h1>\n";
    echo '        <p>' . $messageHtml . "</p>\n";
    simple_page_end();
}
