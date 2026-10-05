<?php

declare(strict_types=1);

use App\Core\Csrf;

/**
 * Il benvenuto: un video e un pulsante, niente altro.
 *
 * @var string $embed
 * @var bool $primaVolta
 */
?>
<div class="benvenuto">
    <div class="page-header benvenuto-testa">
        <h1>Benvenuto in Pistacchio</h1>
        <?php if ($primaVolta): ?>
            <p class="page-subtitle">Due minuti per cominciare con il piede giusto.</p>
        <?php else: ?>
            <p class="page-subtitle">Il video che hai visto al primo accesso.</p>
        <?php endif; ?>
    </div>

    <?php /* Il player sta in un contenitore suo e non in `.video-embed`
             della lezione: li' il massimo e' 720 px perche' accanto c'e'
             una colonna di testo, qui la pagina e' solo questa e il video
             puo' prendersi fino a 900. Il rapporto 16/9 lo impone
             `aspect-ratio`, quindi su un telefono il video e' largo quanto
             lo schermo meno i margini e alto di conseguenza: non serve
             nessuna soglia. */ ?>
    <div class="benvenuto-video"><?= $embed ?></div>

    <?php /* Il pulsante non dipende dall'aver guardato: se Bunny non
             risponde o il player non parte, la persona prosegue lo stesso.
             E' la regola di §8.2 — il peggio che puo' succedere dev'essere
             il comportamento di sempre. */ ?>
    <form action="/benvenuto/visto" method="post" class="benvenuto-azioni">
        <?= Csrf::field() ?>
        <button type="submit" class="btn btn-primary">Vai ai miei corsi</button>
    </form>
</div>
