<?php

declare(strict_types=1);

use App\Core\Csrf;

/**
 * @var array<string, string> $values
 * @var array<string, string> $defaults
 * @var array<string, string> $placeholders
 * @var array|null $lastUpdate
 * @var bool $logTransport
 */

$campi = [
    [
        'titolo' => 'Invito',
        'quando' => 'Parte quando premi “Invia inviti” nella pagina di una sessione. Puoi ripetere l’invio dopo nuove iscrizioni.',
        'oggetto' => 'LIVE_INVITE_SUBJECT',
        'testo' => 'LIVE_INVITE_BODY',
    ],
    [
        'titolo' => 'Cambio di orario',
        'quando' => 'Parte da solo quando salvi una sessione con data o ora diverse da prima. Correggere il titolo non manda niente.',
        'oggetto' => 'LIVE_UPDATE_SUBJECT',
        'testo' => 'LIVE_UPDATE_BODY',
    ],
    [
        'titolo' => 'Annullamento',
        'quando' => 'Parte da solo quando elimini una sessione non ancora conclusa.',
        'oggetto' => 'LIVE_CANCEL_SUBJECT',
        'testo' => 'LIVE_CANCEL_BODY',
    ],
];
?>
<div class="page-header">
    <h1>Inviti alle sessioni live</h1>
    <p class="page-subtitle">
        Oggetto e testo delle email che i partecipanti ricevono per gli incontri dal vivo.
        Un campo lasciato vuoto usa il testo predefinito, quello che si legge in grigio nel campo.
        A ogni messaggio è allegato il file per il calendario.
    </p>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<?php if ($logTransport): ?>
    <p class="empty-state-small">
        L’invio è in modalità “Registro”: i messaggi vengono scritti in <code>storage/mail</code>
        e non raggiungono nessuno. Si cambia nella pagina <a href="/admin/settings/posta">Posta elettronica</a>.
    </p>
<?php endif; ?>

<section class="card">
    <h2>Segnaposto</h2>
    <p class="card-meta">
        Si possono usare sia nell’oggetto sia nel testo; quelli che non esistono restano scritti come sono,
        così un errore di battitura si vede invece di sparire.
    </p>
    <table class="data-table">
        <thead>
        <tr><th>Segnaposto</th><th>Diventa</th></tr>
        </thead>
        <tbody>
        <?php foreach ($placeholders as $segnaposto => $spiegazione): ?>
            <tr>
                <td><code><?= htmlspecialchars($segnaposto) ?></code></td>
                <td><?= htmlspecialchars($spiegazione) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<form action="/admin/settings/inviti" method="post" class="form">
    <?= Csrf::field() ?>

    <?php foreach ($campi as $campo): ?>
        <section class="card">
            <h2><?= htmlspecialchars($campo['titolo']) ?></h2>
            <p class="card-meta"><?= htmlspecialchars($campo['quando']) ?></p>

            <label for="<?= $campo['oggetto'] ?>">Oggetto</label>
            <input type="text" id="<?= $campo['oggetto'] ?>" name="<?= $campo['oggetto'] ?>" maxlength="200"
                   value="<?= htmlspecialchars($values[$campo['oggetto']]) ?>"
                   placeholder="<?= htmlspecialchars($defaults[$campo['oggetto']]) ?>">

            <label for="<?= $campo['testo'] ?>">Testo</label>
            <textarea id="<?= $campo['testo'] ?>" name="<?= $campo['testo'] ?>" rows="15"
                      placeholder="<?= htmlspecialchars($defaults[$campo['testo']]) ?>"><?= htmlspecialchars($values[$campo['testo']]) ?></textarea>
            <p class="form-hint">Lasciando vuoto vale il testo che vedi in grigio.</p>
        </section>
    <?php endforeach; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Salva</button>
    </div>
</form>

<?php if ($lastUpdate !== null): ?>
    <p class="card-meta">
        Ultima modifica da questa pagina:
        <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $lastUpdate['updated_at'])))
            . ($lastUpdate['full_name'] !== null ? ' — ' . htmlspecialchars((string) $lastUpdate['full_name']) : '')
            . '.' ?>
    </p>
<?php endif; ?>
