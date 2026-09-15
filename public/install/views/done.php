<?php

declare(strict_types=1);

defined('LMS_INSTALLER') || exit('Accesso diretto non consentito.');

/** @var bool $envWritten */
/** @var string $envContents */
/** @var string $baseUrl */
?>
<div class="alert alert-success">Installazione completata.</div>

<?php if (!$envWritten): ?>
    <div class="alert alert-warning">
        Non sono riuscito a scrivere il file <code>.env</code>: la cartella del progetto non è
        scrivibile. Crea tu il file nella root del progetto con questo contenuto, poi ricarica il sito.
        Finché <code>.env</code> non esiste, l'applicazione non parte.
    </div>
    <textarea class="env-box" readonly><?= htmlspecialchars($envContents) ?></textarea>
<?php endif; ?>

<section class="card">
    <h2>Due cose da fare adesso</h2>
    <ol>
        <li>
            <strong>Elimina la cartella <code>public/install</code></strong>. La procedura si è già
            bloccata da sola, ma rimuoverla è la garanzia definitiva.
        </li>
        <li>
            In produzione verifica che <code>APP_DEBUG</code> sia <code>0</code> nel file
            <code>.env</code>: gli errori non devono finire sotto gli occhi degli utenti.
        </li>
    </ol>
</section>

<p><a href="<?= htmlspecialchars($baseUrl ?: '/') ?>/login" class="btn btn-primary">Vai all'accesso</a></p>
