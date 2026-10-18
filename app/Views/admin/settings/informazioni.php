<?php

declare(strict_types=1);

/**
 * «Informazioni su Pistacchio LMS» (18/10, Alessandro, variante A): il logo,
 * il nome, la versione installata (file VERSION) e il collegamento al
 * repository, al centro della pagina anche in verticale.
 *
 * @var string|null $versione
 */
?>
<div class="informazioni">
    <a href="/admin/settings" class="back-link"><span class="back-link-testo">Impostazioni</span></a>

    <div class="informazioni-blocco">
        <img class="informazioni-logo" src="/assets/img/pistacchio-180.png" alt="" width="154" height="154">
        <h1>Pistacchio LMS</h1>
        <p class="informazioni-versione">
            <?php if ($versione !== null): ?>
                Versione <strong><?= htmlspecialchars($versione) ?></strong>
            <?php else: ?>
                Versione non indicata (manca il file <code>VERSION</code>)
            <?php endif; ?>
        </p>
        <p class="informazioni-link">
            <a href="https://github.com/agilibaus/lms" target="_blank" rel="noopener noreferrer">github.com/agilibaus/lms<span class="sr-only"> (si apre in una nuova scheda)</span></a>
        </p>
    </div>
</div>
