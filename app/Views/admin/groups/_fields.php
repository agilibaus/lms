<?php

declare(strict_types=1);

/**
 * Campi condivisi fra creazione e modifica del gruppo.
 *
 * @var array|null $group
 * @var array $tutors
 * @var bool $canChooseTutor
 */
?>
<label for="name">Nome del gruppo</label>
<input type="text" id="name" name="name" maxlength="150" required
       value="<?= htmlspecialchars((string) ($group['name'] ?? '')) ?>">

<label for="description">Descrizione</label>
<textarea id="description" name="description" rows="3"><?= htmlspecialchars((string) ($group['description'] ?? '')) ?></textarea>

<?php if ($canChooseTutor): ?>
    <label for="tutor_id">Tutor responsabile</label>
    <select id="tutor_id" name="tutor_id">
        <option value="0">— nessuno —</option>
        <?php foreach ($tutors as $tutor): ?>
            <option value="<?= (int) $tutor['id'] ?>"
                <?= (int) ($group['tutor_id'] ?? 0) === (int) $tutor['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars((string) $tutor['full_name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <p class="form-hint">Il tutor responsabile può gestire il gruppo e vederne i report.</p>
<?php endif; ?>
