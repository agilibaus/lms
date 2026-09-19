<?php

declare(strict_types=1);

use App\Core\GroupLogo;

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

<?php $logo = isset($group) ? GroupLogo::url($group) : null; ?>
<?php if ($logo !== null): ?>
    <p class="hint">Immagine attuale:</p>
    <p><img src="<?= htmlspecialchars($logo) ?>" alt="" class="group-logo group-logo-lg"></p>
<?php endif; ?>

<label for="logo"><?= $logo !== null ? 'Sostituisci immagine' : 'Immagine del gruppo (facoltativa)' ?></label>
<input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/gif,image/webp">
<p class="form-hint">
    Logo o simbolo, fino a 4 MB. Viene ritagliato al centro in quadrato e ridotto a 256 px;
    lo sfondo trasparente dei PNG viene mantenuto. Senza immagine, il gruppo mostra le proprie iniziali.
</p>

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
