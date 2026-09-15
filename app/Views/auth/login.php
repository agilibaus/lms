<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var string|null $error */
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accedi · LMS</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="auth-body">
    <main class="auth-card">
        <h1 class="auth-title">LMS</h1>
        <p class="auth-subtitle">Accedi alla piattaforma corsi</p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form action="/login" method="post" class="auth-form">
    <?= Csrf::field() ?>
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required autofocus autocomplete="username">

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">

            <button type="submit" class="btn btn-primary btn-block">Accedi</button>
        </form>
    </main>
</body>
</html>
