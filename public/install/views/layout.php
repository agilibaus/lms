<?php

declare(strict_types=1);

defined('LMS_INSTALLER') || exit('Accesso diretto non consentito.');

/**
 * Layout della procedura guidata: stesso foglio di stile dell'applicazione,
 * più qualche regola specifica per l'installer.
 *
 * @var string $content
 * @var string $view
 */

$steps = [
    'requirements' => 'Requisiti',
    'database' => 'Database',
    'admin' => 'Amministratore',
    'settings' => 'Impostazioni',
    'done' => 'Fine',
];

$currentIndex = array_search($view, array_keys($steps), true);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installazione · Pistacchio LMS</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" href="/assets/img/pistacchio-32.png" sizes="32x32">
    <link rel="apple-touch-icon" href="/assets/img/pistacchio-180.png">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .install-page { max-width: 760px; margin: 0 auto; padding: 2.5rem 1.25rem 4rem; }
        .install-brand { color: var(--color-primary); font-size: 0.75rem; letter-spacing: 0.12em;
                         text-transform: uppercase; margin: 0; }
        .install-page h1 { font-size: 1.6rem; margin: 0.3rem 0 1.5rem; }
        .install-steps { display: flex; flex-wrap: wrap; gap: 0.4rem; list-style: none;
                         margin: 0 0 1.75rem; padding: 0; font-size: 0.78rem; }
        .install-steps li { padding: 0.3rem 0.7rem; border-radius: 999px;
                            background: var(--color-surface); border: 1px solid var(--color-border);
                            color: var(--color-text-muted); }
        .install-steps .is-current { background: var(--color-primary-soft);
                                     border-color: var(--color-primary); color: var(--color-primary-hover);
                                     font-weight: 600; }
        .install-steps .is-done { color: var(--color-primary); }
        .check-list { list-style: none; margin: 0; padding: 0; }
        .check-list li { display: flex; gap: 0.75rem; align-items: flex-start; padding: 0.6rem 0;
                         border-bottom: 1px solid var(--color-border); }
        .check-list li:last-child { border-bottom: none; }
        .check-mark { flex-shrink: 0; width: 1.5rem; font-weight: 700; }
        .check-ok { color: var(--color-primary); }
        .check-warn { color: #8a6d1f; }
        .check-fail { color: var(--color-danger); }
        .check-label { font-weight: 600; font-size: 0.9rem; }
        .check-detail { display: block; color: var(--color-text-muted); font-size: 0.82rem; margin-top: 0.1rem; }
        .env-box { width: 100%; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                   font-size: 0.8rem; min-height: 320px; }
    </style>
</head>
<body>
<div class="install-page">
    <p class="install-brand">Pistacchio LMS</p>
    <h1>Installazione</h1>

    <?php if ($view !== 'locked'): ?>
        <ol class="install-steps">
            <?php foreach (array_values($steps) as $index => $label): ?>
                <li class="<?= $index === $currentIndex ? 'is-current' : ($currentIndex !== false && $index < $currentIndex ? 'is-done' : '') ?>">
                    <?= $index + 1 ?>. <?= htmlspecialchars($label) ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <?= $content ?>
</div>
</body>
</html>
