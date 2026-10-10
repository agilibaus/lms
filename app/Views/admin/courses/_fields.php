<?php

declare(strict_types=1);

use App\Controllers\Admin\CourseController;

/**
 * Campi condivisi fra creazione e modifica del corso.
 *
 * @var array|null $course
 */

// La descrizione ha un limite di 3.000 caratteri (10/10) e il contatore
// sopra l'angolo in alto a destra, come la presentazione del profilo e la
// domanda all'esperto: stesse classi e stesso script. Il numero di partenza
// lo scrive il server, contando quello che c'e' gia', con gli a capo contati
// come li conta il campo: senza JavaScript il contatore resta fermo, ma non
// dice una cosa falsa.
$descrizione = (string) ($course['description'] ?? '');
$restano = max(0, CourseController::MAX_DESCRIPTION_CHARS - mb_strlen(str_replace("\r\n", "\n", $descrizione)));
?>
<label for="title">Titolo</label>
<input type="text" id="title" name="title" maxlength="200" required
       value="<?= htmlspecialchars((string) ($course['title'] ?? '')) ?>">

<label for="slug">Slug (facoltativo)</label>
<input type="text" id="slug" name="slug" maxlength="220"
       value="<?= htmlspecialchars((string) ($course['slug'] ?? '')) ?>">
<p class="form-hint">Se lo lasci vuoto viene generato dal titolo; se è già in uso viene reso univoco con un suffisso.</p>

<div class="quiz-open-wrap campo-contato">
    <div class="campo-contato-testa">
        <label for="description">Descrizione</label>
        <span class="quiz-open-count" id="description-resta" data-max="<?= CourseController::MAX_DESCRIPTION_CHARS ?>">
            <?= $restano ?> <?= $restano === 1 ? 'carattere rimasto' : 'caratteri rimasti' ?>
        </span>
    </div>
    <textarea id="description" name="description" rows="4" maxlength="<?= CourseController::MAX_DESCRIPTION_CHARS ?>"
              class="quiz-open-answer" aria-describedby="description-resta"><?= htmlspecialchars($descrizione) ?></textarea>
</div>
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
