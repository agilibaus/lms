<?php

declare(strict_types=1);

use App\Controllers\LiveSessionController;
use App\Core\CampoContato;
use App\Core\Csrf;

/** @var array|null $session */
/** @var array $modules */
/** @var array $groups */
/** @var bool $googleConfigured */

$isEdit = $session !== null;
// L'id dell'incontro, 0 quando si sta creando: la vista serve a entrambe le cose,
// e la riga esiste solo nel secondo caso.
$sessionId = (int) ($session['id'] ?? 0);
$action = $isEdit ? '/live/' . $sessionId : '/live';

$toInput = static function (?string $value): string {
    return $value === null || $value === ''
        ? ''
        : (new DateTimeImmutable($value))->format('Y-m-d\TH:i');
};
?>
<div class="page-header">
    <a href="/live" class="back-link"><span class="back-link-testo">Sessioni live</span></a>
    <h1><?= $isEdit ? 'Modifica sessione' : 'Nuova sessione live' ?></h1>
</div>

<?php require __DIR__ . '/../admin/_flash.php'; ?>

<?php if (!$googleConfigured): ?>
    <div class="alert alert-warning">
        Google Calendar non è configurato: nessun link Meet verrà creato automaticamente.
        Puoi incollarne uno creato a mano nel campo in fondo.
    </div>
<?php endif; ?>

<form action="<?= htmlspecialchars($action) ?>" method="post" class="form">
    <?= Csrf::field() ?>

    <label for="title">Titolo</label>
    <input type="text" id="title" name="title" maxlength="200" required
           value="<?= htmlspecialchars((string) ($session['title'] ?? '')) ?>">

    <?= CampoContato::html('description', 'Descrizione', 'description', LiveSessionController::MAX_DESCRIPTION_CHARS,
        (string) ($session['description'] ?? ''), 3) ?>
    <script src="/assets/js/quiz-open-count.js"></script>

    <label for="starts_at">Inizio</label>
    <input type="datetime-local" id="starts_at" name="starts_at" required
           value="<?= htmlspecialchars($toInput($session['starts_at'] ?? null)) ?>">

    <label for="ends_at">Fine</label>
    <input type="datetime-local" id="ends_at" name="ends_at" required
           value="<?= htmlspecialchars($toInput($session['ends_at'] ?? null)) ?>">

    <?php if ($soloGruppo): ?>
        <?php /* Il tutor (26/10, conflitto 2): prima il gruppo, obbligatorio;
                 il modulo e' il tema, facoltativo. Gli incontri per tutto il
                 corso li crea l'admin. */ ?>
        <label for="group_id">Gruppo</label>
        <select id="group_id" name="group_id" required>
            <?php foreach ($groups as $group): ?>
                <option value="<?= (int) $group['id'] ?>"
                    <?= (int) ($session['group_id'] ?? 0) === (int) $group['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) $group['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="module_id">Modulo (facoltativo)</label>
        <select id="module_id" name="module_id" aria-describedby="live-destinatari">
            <option value="0">— nessuno —</option>
            <?php foreach ($modules as $module): ?>
                <option value="<?= (int) $module['id'] ?>"
                    <?= (int) ($session['module_id'] ?? 0) === (int) $module['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) $module['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="form-hint" id="live-destinatari">
            L’incontro è per il gruppo. Il modulo, se lo indichi, ne è il tema: lo studente lo vede da quando il
            modulo è aperto. Gli incontri per tutto il corso li crea l’amministrazione.
        </p>
    <?php else: ?>
        <label for="module_id">Modulo di corso</label>
        <select id="module_id" name="module_id">
            <option value="0">— nessuno —</option>
            <?php foreach ($modules as $module): ?>
                <option value="<?= (int) $module['id'] ?>"
                    <?= (int) ($session['module_id'] ?? 0) === (int) $module['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) $module['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="group_id">Gruppo</label>
        <select id="group_id" name="group_id">
            <option value="0">— nessuno —</option>
            <?php foreach ($groups as $group): ?>
                <option value="<?= (int) $group['id'] ?>"
                    <?= (int) ($session['group_id'] ?? 0) === (int) $group['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) $group['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="form-hint">
            Indica almeno uno dei due. Con il solo modulo l’incontro è per tutti gli iscritti al corso che non l’hanno
            ancora finito; con un gruppo è solo per quel gruppo, e il modulo ne è il tema: lo studente lo vede da quando
            il modulo è aperto.
        </p>

    <?php endif; ?>

    <label for="meet_link">Link Meet manuale (facoltativo)</label>
    <input type="url" id="meet_link" name="meet_link" maxlength="255" placeholder="https://meet.google.com/..."
           value="<?= htmlspecialchars((string) ($session['meet_link'] ?? '')) ?>">
    <p class="form-hint">
        Se lo compili, viene usato questo link e la sessione non viene sincronizzata con Google Calendar.
        Lasciandolo vuoto, il link viene creato automaticamente insieme all'evento (se Google è configurato).
    </p>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Salva' : 'Crea sessione' ?></button>
        <a href="/live" class="btn btn-secondary">Annulla</a>
    </div>
</form>
