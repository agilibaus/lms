<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Core\Csrf;

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
            <?php if (!Auth::hasRole('admin', 'tutor', 'assistente')): ?>
                <a href="/catalogo" class="nav-link">Esplora corsi</a>
            <?php endif; ?>
            <?php if (Auth::canAny('report.view', 'report.view_assigned')): ?>
                <a href="/reports" class="nav-link">Report</a>
            <?php endif; ?>
            <a href="/live" class="nav-link">Sessioni live</a>
            <a href="/certificates" class="nav-link">Certificati</a>

            <?php if (Auth::canAny('user.manage', 'assistant.manage', 'group.manage', 'group.manage_own', 'course.create', 'course.edit', 'course.delete') || Auth::hasRole('admin')): ?>
                <span class="nav-section">Amministrazione</span>

                <?php if (Auth::canAny('course.create', 'course.edit', 'course.delete')): ?>
                    <a href="/admin/courses" class="nav-link">Gestione corsi</a>
                <?php endif; ?>
                <?php if (Auth::canAny('group.manage', 'group.manage_own')): ?>
                    <a href="/admin/groups" class="nav-link">Gruppi</a>
                <?php endif; ?>
                <?php if (Auth::canAny('user.manage', 'assistant.manage')): ?>
                    <a href="/admin/users" class="nav-link">Utenti</a>
                <?php endif; ?>
                <?php if (Auth::hasRole('admin')): ?>
                    <a href="/admin/permissions" class="nav-link">Permessi</a>
                <?php endif; ?>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <span class="user-name"><?= htmlspecialchars(Auth::name() ?? '') ?></span>
            <form action="/logout" method="post">
    <?= Csrf::field() ?>
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
