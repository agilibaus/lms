<?php

declare(strict_types=1);

/**
 * Cornice condivisa dalle pagine pubbliche di accesso, registrazione e
 * recupero password.
 *
 * @var string $pageTitle
 * @var string $cardContent
 */
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> · Pistacchio LMS</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="auth-body">
<main class="auth-card">
    <h1 class="auth-title">Pistacchio LMS</h1>
    <?= $cardContent ?>
</main>
</body>
</html>
