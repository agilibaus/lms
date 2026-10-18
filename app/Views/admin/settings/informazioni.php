<?php

declare(strict_types=1);

/**
 * «Informazioni su Pistacchio LMS» (18/10, Alessandro, variante A): il logo,
 * il nome, la versione installata (file VERSION) e il collegamento al
 * repository, al centro della pagina anche in verticale.
 *
 * Sotto, il riquadro con i dati per l'assistenza (18/10): su computer in
 * una riga, etichetta e valore affiancati; sul telefono uno sotto l'altro,
 * con l'etichetta sopra il valore.
 *
 * @var string|null $versione
 * @var array<string, string> $dati
 */
?>
<div class="informazioni">
    <a href="/admin/settings" class="back-link"><span class="back-link-testo">Impostazioni</span></a>

    <div class="informazioni-blocco">
        <img class="informazioni-logo" src="/assets/img/pistacchio-180.png" alt="" width="169" height="169">
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
        <dl class="informazioni-dati">
            <?php foreach ($dati as $etichetta => $valore): ?>
                <div class="informazioni-dato">
                    <dt class="informazioni-etichetta"><?= htmlspecialchars($etichetta) ?></dt>
                    <dd class="informazioni-valore"><?= htmlspecialchars($valore) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </div>
</div>
