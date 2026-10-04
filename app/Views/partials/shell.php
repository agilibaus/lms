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
<?php /* I colori scelti dal pannello, DOPO il foglio di stile: l'ultima
         dichiarazione vince, e cosi' `style.css` non viene mai riscritto —
         le patch future non ci vanno in conflitto (§8.5). Niente quando non
         c'e' niente da cambiare. */ ?>
<?php $coloriTema = App\Core\Theme::bloccoFont() . App\Core\Theme::blocco(); ?>
<?php if ($coloriTema !== ''): ?>
    <style><?= $coloriTema ?></style>
<?php endif; ?>
</head>
<body>
<div class="app-shell">
    <?php /* Due comandi distinti per due comportamenti distinti, non uno solo:
             su telefono "spuntato" vuol dire cassetto aperto, su desktop vuol
             dire menu nascosto. Con una casella sola lo stato si rovescerebbe
             passando da una misura all'altra, e la preferenza ricordata
             arriverebbe al telefono con il significato opposto. */ ?>
    <input type="checkbox" id="nav-toggle" class="nav-toggle-checkbox">
    <?php /* La striscia dietro al pulsante del menu, solo sotto i 768 px.
             Il pulsante e' fermo in alto a sinistra e la pagina gli scorre
             sotto: da solo e' un quadratino di 38 px che si confonde con
             qualunque cosa passi sotto, e che copre cio' che ci finisce
             dietro. Con la striscia il contenuto scorre sotto una fascia
             piena larga quanto lo schermo, come in qualsiasi applicazione
             con l'intestazione fissa. Non ha contenuto ne' ruolo: e'
             decorazione, quindi `aria-hidden`. */ ?>
    <div class="nav-bar" aria-hidden="true"></div>
    <label for="nav-toggle" class="nav-toggle-btn" aria-label="Apri menu">
        <span></span><span></span><span></span>
    </label>
    <label for="nav-toggle" class="nav-overlay" aria-hidden="true"></label>

    <?php /* Su desktop il comando e' la casella stessa, trasparente e sovrapposta
             al disegno del pulsante: cosi' si raggiunge con il tabulatore e si
             attiva con la barra spaziatrice, senza JavaScript. Lo stato lo dice
             la casella («spuntato» = menu nascosto): `aria-expanded` non e'
             previsto su una casella di spunta e non viene usato. */ ?>
    <input type="checkbox" id="nav-collapse" class="nav-collapse-checkbox"
           aria-label="Nascondi il menu di navigazione">
    <span class="nav-collapse-btn" aria-hidden="true">
        <span></span><span></span><span></span>
    </span>
    <script>
        /* Il ripristino sta qui, subito dopo la casella, e non in fondo alla
           pagina: uno script in fondo farebbe vedere il menu aperto per un
           istante prima di richiuderlo. Senza JavaScript il menu parte aperto
           a ogni pagina, come deciso. */
        (function () {
            var chiave = 'pistacchio-nav-collapsed';
            var casella = document.getElementById('nav-collapse');

            try {
                if (localStorage.getItem(chiave) === '1') {
                    casella.checked = true;
                }
            } catch (e) {
                /* Navigazione privata o archiviazione negata: si prosegue
                   senza ricordare nulla. */
            }

            casella.addEventListener('change', function () {
                try {
                    localStorage.setItem(chiave, casella.checked ? '1' : '0');
                } catch (e) {
                }
            });
        })();
    </script>

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
            <?php /* L'Agenda sta sopra a «Sessioni live» perche' risponde a
                     una domanda piu' frequente — «che cosa mi aspetta» — e
                     perche' le sessioni sono una delle cose che contiene.
                     Restano due voci: «Sessioni live» e' il posto dove lo
                     staff le crea e le modifica, l'agenda e' il posto dove
                     si guardano. */ ?>
            <a href="/agenda" class="nav-link<?= $voce('/agenda') ?>">Agenda</a>
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
                <?php /* Cinque pagine di configurazione sotto una voce sola: si
                         toccano una volta e poi quasi mai, e una voce si guadagna
                         il posto nel menu solo se e' un luogo dove si torna in
                         giorni diversi (Sezione 4). Corsi, gruppi e utenti restano
                         fuori: quelli sono il lavoro quotidiano.

                         L'evidenziazione e' per prefisso, quindi la voce resta
                         accesa dentro ogni /admin/settings/... ; la pagina dei
                         permessi vive altrove e va aggiunta a mano. */ ?>
                <?php if (Auth::can('settings.manage') || Auth::hasRole('admin')): ?>
                    <?php $inImpostazioni = $voce('/admin/settings') !== '' || $voce('/admin/permissions') !== ''; ?>
                    <a href="/admin/settings"
                       class="nav-link<?= $inImpostazioni ? ' nav-link-active" aria-current="page' : '' ?>">Impostazioni</a>
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
