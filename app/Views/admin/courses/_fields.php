<?php

declare(strict_types=1);

use App\Controllers\Admin\CourseController;
use App\Core\TestoBreve;

/**
 * Campi condivisi fra creazione e modifica del corso.
 *
 * @var array|null $course
 */

// Obiettivo e descrizione (11/10) accettano qualche tag HTML scritto a mano,
// e il limite conta solo il testo visibile: niente `maxlength`, che
// conterebbe anche i tag, e oltre il limite il server rifiuta e ripropone il
// testo. Il contatore e l'aiuto sui tag li scrive `TestoBreve::campo()`; lo
// script del contatore e' lo stesso degli altri campi.
?>
<label for="title">Titolo</label>
<input type="text" id="title" name="title" maxlength="200" required
       value="<?= htmlspecialchars((string) ($course['title'] ?? '')) ?>">

<label for="slug">Slug (facoltativo)</label>
<input type="text" id="slug" name="slug" maxlength="220"
       value="<?= htmlspecialchars((string) ($course['slug'] ?? '')) ?>">
<p class="form-hint">Se lo lasci vuoto viene generato dal titolo; se è già in uso viene reso univoco con un suffisso.</p>

<?= TestoBreve::campo('objective', 'Obiettivo del corso', 'objective', CourseController::MAX_OBJECTIVE_CHARS,
    (string) ($course['objective'] ?? ''), 3) ?>

<?= TestoBreve::campo('description', 'Descrizione', 'description', CourseController::MAX_DESCRIPTION_CHARS,
    (string) ($course['description'] ?? ''), 4) ?>
<script src="/assets/js/quiz-open-count.js"></script>

<label class="checkbox-label">
    <input type="checkbox" name="is_published" value="1" <?= !empty($course['is_published']) ? 'checked' : '' ?>>
    Pubblicato (una bozza resta visibile solo allo staff)
</label>

<label for="enrollment_mode">Come ci si iscrive</label>
<?php $mode = $course['enrollment_mode'] ?? 'closed'; ?>
<select id="enrollment_mode" name="enrollment_mode">
    <option value="closed" <?= $mode === 'closed' ? 'selected' : '' ?>>
        Chiusa — iscrive solo lo staff, o l'assegnazione a un gruppo
    </option>
    <option value="request" <?= $mode === 'request' ? 'selected' : '' ?>>
        Su richiesta — lo studente chiede, un tutor approva
    </option>
    <option value="open" <?= $mode === 'open' ? 'selected' : '' ?>>
        Aperta — lo studente si iscrive da solo dal catalogo
    </option>
</select>
<p class="form-hint">
    Le ultime due modalità mostrano il corso nel catalogo, ma solo se è pubblicato.
</p>
