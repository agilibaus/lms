<?php

declare(strict_types=1);

use App\Core\Quando;

/**
 * La pagina pubblica dell'informativa privacy (18/11, Alessandro).
 *
 * Si legge senza accedere, e fuori dal guscio dell'applicazione: in cima
 * una barra che resta ferma mentre si scorre, con un solo pulsante grande
 * che riporta da dove si e' arrivati (`Privacy::ritorno()`). Nessun
 * riquadro sopra la pagina: su iPhone il gesto «indietro» non lo chiuderebbe
 * e porterebbe via dal modulo. Qui «indietro» funziona come su ogni pagina.
 *
 * @var string $pageTitle
 * @var array{id: int, content_html: string, published_at: string, corrected_at: ?string}|null $versione
 * @var bool $precedente una versione non piu' in vigore, aperta dall'Amministratore
 * @var string $tornaA
 * @var string $tornaTesto
 */
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
    <link rel="preload" href="/assets/fonts/albert-sans-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/css/style.css">
<?php /* I colori e il carattere scelti dal pannello, come nelle altre pagine. */ ?>
<?php $coloriTema = App\Core\Theme::bloccoFont() . App\Core\Theme::blocco(); ?>
<?php if ($coloriTema !== ''): ?>
    <style><?= $coloriTema ?></style>
<?php endif; ?>
</head>
<body class="privacy-body">
<header class="privacy-barra">
    <div class="privacy-barra-dentro">
        <a href="<?= htmlspecialchars($tornaA) ?>" class="btn btn-primary"><?= htmlspecialchars($tornaTesto) ?></a>
        <span class="privacy-marchio">
            <img src="/assets/img/pistacchio.png" alt="" width="26" height="26">
            <span>Pistacchio LMS</span>
        </span>
    </div>
</header>

<main class="privacy-testo">
    <h1>Informativa sulla privacy</h1>

    <?php if ($versione === null): ?>
        <p>L’informativa non è ancora stata pubblicata.</p>
    <?php else: ?>
        <p class="privacy-versione">
            <?php if ($precedente): ?>
                Versione del <?= htmlspecialchars(Quando::data($versione['published_at'])) ?>, non più in vigore
            <?php else: ?>
                Versione del <?= htmlspecialchars(Quando::data($versione['published_at'])) ?>
            <?php endif; ?>
        </p>
        <?php /* Il testo passa dal sanificatore quando l'Amministratore lo salva
                 (`Privacy::pulisci`), come quello delle lezioni. */ ?>
        <div class="lesson-content privacy-contenuto">
            <?= $versione['content_html'] ?>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
