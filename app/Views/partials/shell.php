<?php

declare(strict_types=1);

use App\Auth\Auth;

/** @var string $content */
/** @var string|null $pageTitle */
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'LMS') ?> · LMS</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="app-shell">
    <input type="checkbox" id="nav-toggle" class="nav-toggle-checkbox">
    <label for="nav-toggle" class="nav-toggle-btn" aria-label="Apri menu">
        <span></span><span></span><span></span>
    </label>
    <label for="nav-toggle" class="nav-overlay" aria-hidden="true"></label>

    <aside class="sidebar">
        <div class="sidebar-brand">LMS</div>

        <nav class="sidebar-nav">
            <a href="/" class="nav-link">Corsi</a>
            <?php if (Auth::hasRole('admin', 'tutor')): ?>
                <a href="/groups" class="nav-link">Gruppi</a>
            <?php endif; ?>
            <?php if (Auth::hasRole('admin', 'tutor', 'assistente')): ?>
                <a href="/reports" class="nav-link">Report</a>
            <?php endif; ?>
            <?php if (Auth::hasRole('admin')): ?>
                <a href="/users" class="nav-link">Utenti</a>
            <?php endif; ?>
            <a href="/certificates" class="nav-link">Certificati</a>
        </nav>

        <div class="sidebar-footer">
            <span class="user-name"><?= htmlspecialchars(Auth::name() ?? '') ?></span>
            <form action="/logout" method="post">
                <button type="submit" class="link-btn">Esci</button>
            </form>
        </div>
    </aside>

    <main class="main-content">
        <?= $content ?>
    </main>
</div>
</body>
</html>
