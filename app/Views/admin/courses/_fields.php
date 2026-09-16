<?php

declare(strict_types=1);

/**
 * Campi condivisi fra creazione e modifica del corso.
 *
 * @var array|null $course
 */
?>
<label for="title">Titolo</label>
<input type="text" id="title" name="title" maxlength="200" required
       value="<?= htmlspecialchars((string) ($course['title'] ?? '')) ?>">

<label for="slug">Slug (facoltativo)</label>
<input type="text" id="slug" name="slug" maxlength="220"
       value="<?= htmlspecialchars((string) ($course['slug'] ?? '')) ?>">
<p class="form-hint">Se lo lasci vuoto viene generato dal titolo; se è già in uso viene reso univoco con un suffisso.</p>

<label for="description">Descrizione</label>
<textarea id="description" name="description" rows="4"><?= htmlspecialchars((string) ($course['description'] ?? '')) ?></textarea>

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
