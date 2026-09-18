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
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" href="/assets/img/pistacchio-32.png" sizes="32x32">
    <link rel="apple-touch-icon" href="/assets/img/pistacchio-180.png">
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
        <div class="sidebar-brand">
            <?php /* alt vuoto: il nome sta gia' scritto accanto, e un lettore
                     di schermo lo direbbe due volte. */ ?>
            <img src="/assets/img/pistacchio.png" alt="" width="26" height="26" class="brand-icon">
            LMS
        </div>

        <?php
        $percorso = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

        /**
         * Segna la voce corrispondente alla pagina aperta.
         *
         * Il confronto e' per prefisso, cosi' restano segnate anche le pagine
         * interne di una sezione: /admin/courses/3/edit tiene acceso
         * "Gestione corsi". La casa fa eccezione, altrimenti il prefisso "/"
         * starebbe sotto a tutto.
         *
         * Le pagine che non appartengono a nessuna voce — un corso, una
         * lezione, un modulo, un quiz — non ne accendono nessuna: li' si sta
         * dentro un contenuto, non in una sezione del menu.
         */
        $voce = static function (string $href) use ($percorso): string {
            $attiva = $href === '/'
                ? $percorso === '/'
                : ($percorso === $href || str_starts_with($percorso, $href . '/'));

            return $attiva ? ' nav-link-active" aria-current="page' : '';
        };
        ?>
        <nav class="sidebar-nav">
            <?php if (Auth::hasRole('admin', 'tutor', 'assistente')): ?>
                <a href="/" class="nav-link<?= $voce('/') ?>">Corsi</a>
            <?php else: ?>
                <a href="/" class="nav-link<?= $voce('/') ?>">I miei corsi</a>
                <a href="/catalogo" class="nav-link<?= $voce('/catalogo') ?>">Esplora corsi</a>
            <?php endif; ?>
            <?php if (Auth::canAny('report.view', 'report.view_assigned')): ?>
                <a href="/reports" class="nav-link<?= $voce('/reports') ?>">Report</a>
            <?php endif; ?>
            <a href="/live" class="nav-link<?= $voce('/live') ?>">Sessioni live</a>
            <a href="/certificates" class="nav-link<?= $voce('/certificates') ?>">Certificati</a>
            <a href="/profilo" class="nav-link<?= $voce('/profilo') ?>">Profilo</a>

            <?php if (Auth::canAny('user.manage', 'assistant.manage', 'group.manage', 'group.manage_own', 'course.create', 'course.edit', 'course.delete', 'settings.manage') || Auth::hasRole('admin')): ?>
                <span class="nav-section">Amministrazione</span>

                <?php if (Auth::canAny('course.create', 'course.edit', 'course.delete')): ?>
                    <a href="/admin/courses" class="nav-link<?= $voce('/admin/courses') ?>">Gestione corsi</a>
                <?php endif; ?>
                <?php if (Auth::canAny('group.manage', 'group.manage_own')): ?>
                    <a href="/admin/groups" class="nav-link<?= $voce('/admin/groups') ?>">Gruppi</a>
                <?php endif; ?>
                <?php if (Auth::canAny('user.manage', 'assistant.manage')): ?>
                    <a href="/admin/users" class="nav-link<?= $voce('/admin/users') ?>">Utenti</a>
                <?php endif; ?>
                <?php if (Auth::can('settings.manage')): ?>
                    <a href="/admin/settings/posta" class="nav-link<?= $voce('/admin/settings/posta') ?>">Posta elettronica</a>
                    <a href="/admin/settings/meet" class="nav-link<?= $voce('/admin/settings/meet') ?>">Google Meet</a>
                <?php endif; ?>
                <?php if (Auth::hasRole('admin')): ?>
                    <a href="/admin/permissions" class="nav-link<?= $voce('/admin/permissions') ?>">Permessi</a>
                <?php endif; ?>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <a class="sidebar-user" href="/profilo">
                <?php if (Auth::avatar() !== null): ?>
                    <img class="avatar avatar-sm" src="/utenti/<?= (int) Auth::id() ?>/immagine" alt="">
                <?php else: ?>
                    <span class="avatar avatar-sm avatar-placeholder" aria-hidden="true">
                        <?= htmlspecialchars(mb_strtoupper(mb_substr(Auth::name() ?? '?', 0, 1))) ?>
                    </span>
                <?php endif; ?>
                <span class="user-name"><?= htmlspecialchars(Auth::name() ?? '') ?></span>
            </a>
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
