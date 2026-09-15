<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var array $module */
/** @var array|null $lesson */
/** @var array $materials */
$isEdit = $lesson !== null;
$action = $isEdit ? '/lessons/' . $lesson['id'] : '/modules/' . $module['id'] . '/lessons';
$provider = $lesson['video_provider'] ?? 'none';
?>
<div class="page-header">
    <a href="/courses/<?= (int) $module['course_id'] ?>" class="back-link">&larr; Torna al corso</a>
    <h1><?= $isEdit ? 'Modifica lezione' : 'Nuova lezione' ?> &mdash; <?= htmlspecialchars($module['title']) ?></h1>
</div>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<form action="<?= htmlspecialchars($action) ?>" method="post" enctype="multipart/form-data" class="stacked-form">
    <?= Csrf::field() ?>
    <label for="title">Titolo</label>
    <input type="text" id="title" name="title" required value="<?= htmlspecialchars($lesson['title'] ?? '') ?>">

    <label for="content_html">Contenuto / istruzioni</label>
    <textarea id="content_html" name="content_html" rows="6"><?= htmlspecialchars($lesson['content_html'] ?? '') ?></textarea>

    <label for="duration_seconds">Durata (secondi)</label>
    <input type="number" id="duration_seconds" name="duration_seconds" min="0"
           value="<?= (int) ($lesson['duration_seconds'] ?? 0) ?>">

    <fieldset class="video-fieldset">
        <legend>Video</legend>

        <label for="video_provider">Provider</label>
        <select id="video_provider" name="video_provider"
                onchange="document.querySelectorAll('.video-provider-fields').forEach(function (el) { el.hidden = el.dataset.provider !== this.value; }, this)">
            <option value="none" <?= $provider === 'none' ? 'selected' : '' ?>>Nessuno</option>
            <option value="bunny" <?= $provider === 'bunny' ? 'selected' : '' ?>>Bunny Stream</option>
            <option value="cloudflare" <?= $provider === 'cloudflare' ? 'selected' : '' ?>>Cloudflare Stream</option>
            <option value="self_hosted" <?= $provider === 'self_hosted' ? 'selected' : '' ?>>Self-hosted (upload)</option>
        </select>

        <div class="video-provider-fields" data-provider="bunny" <?= $provider !== 'bunny' ? 'hidden' : '' ?>>
            <label for="video_ref_bunny">ID video Bunny Stream</label>
            <input type="text" id="video_ref_bunny" name="video_ref"
                   value="<?= $provider === 'bunny' ? htmlspecialchars($lesson['video_ref'] ?? '') : '' ?>">
        </div>

        <div class="video-provider-fields" data-provider="cloudflare" <?= $provider !== 'cloudflare' ? 'hidden' : '' ?>>
            <label for="video_ref_cf">ID video Cloudflare Stream</label>
            <input type="text" id="video_ref_cf" name="video_ref"
                   value="<?= $provider === 'cloudflare' ? htmlspecialchars($lesson['video_ref'] ?? '') : '' ?>">
        </div>

        <div class="video-provider-fields" data-provider="self_hosted" <?= $provider !== 'self_hosted' ? 'hidden' : '' ?>>
            <p class="hint">Sconsigliato in produzione: preferisci Bunny/Cloudflare Stream per non appesantire il server.</p>
            <?php if ($isEdit && $provider === 'self_hosted' && !empty($lesson['video_ref'])): ?>
                <p class="hint">Video attuale: <code><?= htmlspecialchars($lesson['video_ref']) ?></code></p>
            <?php endif; ?>
            <label for="video_file">Carica file video (mp4/webm/mov/m4v, max 500&nbsp;MB)</label>
            <input type="file" id="video_file" name="video_file" accept="video/*">
        </div>
    </fieldset>

    <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Salva' : 'Crea lezione' ?></button>
</form>

<?php if ($isEdit): ?>
    <section class="materials-section">
        <h3>Materiali scaricabili</h3>

        <?php if (empty($materials)): ?>
            <p class="empty-state-small">Nessun materiale caricato.</p>
        <?php else: ?>
            <ul class="material-list">
                <?php foreach ($materials as $material): ?>
                    <li>
                        <span><?= htmlspecialchars($material['file_name']) ?></span>
                        <span class="material-size"><?= round($material['file_size_bytes'] / 1024) ?>&nbsp;KB</span>
                        <form action="/lessons/<?= (int) $lesson['id'] ?>/materials/<?= (int) $material['id'] ?>/delete"
                              method="post" onsubmit="return confirm('Eliminare questo materiale?');">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-btn">Elimina</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form action="/lessons/<?= (int) $lesson['id'] ?>/materials" method="post" enctype="multipart/form-data" class="stacked-form">
            <?= Csrf::field() ?>
            <label for="materials">Aggiungi materiali (PDF, audio, doc, zip &mdash; max 50&nbsp;MB ciascuno)</label>
            <input type="file" id="materials" name="materials[]" multiple>
            <button type="submit" class="btn btn-primary">Carica</button>
        </form>
    </section>

    <form action="/lessons/<?= (int) $lesson['id'] ?>/delete" method="post"
          onsubmit="return confirm('Eliminare definitivamente questa lezione e i suoi materiali?');">
        <?= Csrf::field() ?>
        <button type="submit" class="link-btn">Elimina lezione</button>
    </form>
<?php endif; ?>
