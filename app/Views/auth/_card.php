<?php

declare(strict_types=1);

use App\Core\AuthLayout;

/**
 * Cornice condivisa dalle pagine pubbliche di accesso, registrazione e
 * recupero password, e dalla pagina di cambio password obbligato.
 *
 * Due aspetti, scelti dal pannello (Aspetto): "guscio", il riquadro centrato
 * di sempre, e "affiancato", con la presentazione a sinistra e il modulo a
 * destra. Il contenuto — `$cardContent` — e' lo stesso nei due casi: cambia
 * solo cio' che gli sta intorno, e nessuna pagina va scritta due volte.
 *
 * @var string $pageTitle
 * @var string $cardContent
 */

$aspetto = AuthLayout::current();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> · Pistacchio LMS</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" href="/assets/img/pistacchio-32.png" sizes="32x32">
    <link rel="apple-touch-icon" href="/assets/img/pistacchio-180.png">
    <?php /* Il font viene chiesto subito, in parallelo al foglio di stile,
             invece di aspettare che il CSS sia letto e analizzato: senza
             questa riga il testo compare prima nel carattere di sistema e
             cambia sotto gli occhi un attimo dopo. `crossorigin` serve anche
             se il file e' nostro — i font si scaricano sempre in modalita'
             CORS, e senza quell'attributo il browser lo scaricherebbe due
             volte. */ ?>
    <link rel="preload" href="/assets/fonts/albert-sans-latin.woff2" as="font"
          type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/css/style.css">
<?php /* I colori scelti dal pannello, DOPO il foglio di stile: l'ultima
         dichiarazione vince, e cosi' `style.css` non viene mai riscritto —
         le patch future non ci vanno in conflitto (§8.5). Niente quando non
         c'e' niente da cambiare. */ ?>
<?php /* I ritocchi sui singoli elementi stanno in fondo, dopo le variabili:
         sono regole piu' specifiche e devono poter vincere su quelle del
         foglio di stile. Valgono solo qui, per questo il guscio
         dell'applicazione non li stampa. */ ?>
<?php $coloriTema = App\Core\Theme::bloccoFont() . App\Core\Theme::blocco()
    . App\Core\ElementStyle::blocco(); ?>
<?php if ($coloriTema !== ''): ?>
    <style><?= $coloriTema ?></style>
<?php endif; ?>
</head>
<?php if ($aspetto === AuthLayout::AFFIANCATO): ?>
<body class="auth-body-split">
<div class="auth-split">
    <section class="auth-scene">
        <div class="scene-brand">
            <?php /* alt vuoto: il nome sta gia' scritto accanto, e un lettore
                     di schermo lo direbbe due volte. */ ?>
            <img src="/assets/img/pistacchio.png" alt="" width="34" height="34" class="scene-mark">
            Pistacchio LMS
        </div>

        <div class="scene-claim">
            <?php /* Gli a capo scritti dall'admin diventano <br>: e' lui a
                     decidere dove spezza il titolo, non la larghezza della
                     finestra. Il testo passa prima da htmlspecialchars. */ ?>
            <h2><?= nl2br(htmlspecialchars(AuthLayout::title()), false) ?></h2>
            <p><?= nl2br(htmlspecialchars(AuthLayout::text()), false) ?></p>
        </div>
    </section>

    <section class="auth-panel">
        <div class="auth-panel-inner">
            <?php /* Qui il titolo della pagina — "Accedi", "Registrati" — e non
                     il nome della piattaforma, che sta gia' a sinistra. Nel
                     guscio e' il contrario: li' non c'e' altro posto per il
                     marchio. */ ?>
            <h1 class="auth-panel-title"><?= htmlspecialchars($pageTitle) ?></h1>
            <?= $cardContent ?>
        </div>
    </section>
</div>
</body>
<?php else: ?>
<body class="auth-body">
<main class="auth-card">
    <h1 class="auth-title">Pistacchio LMS</h1>
    <?= $cardContent ?>
</main>
</body>
<?php endif; ?>
</html>
