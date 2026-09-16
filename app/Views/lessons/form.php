<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\FileType;

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

<form action="<?= htmlspecialchars($action) ?>" method="post" enctype="multipart/form-data" class="stacked-form lesson-form">
    <?= Csrf::field() ?>
    <label for="title">Titolo</label>
    <input type="text" id="title" name="title" required value="<?= htmlspecialchars($lesson['title'] ?? '') ?>">

    <label for="content_html">Contenuto della lezione</label>
    <textarea id="content_html" name="content_html" rows="18"><?= htmlspecialchars($lesson['content_html'] ?? '') ?></textarea>
    <p class="hint">
        Testo formattato, elenchi, tabelle, immagini e video incorporati da YouTube o Vimeo
        (pulsante <em>Inserisci &rarr; Media</em>).
        <?php if (!$isEdit): ?>
            Le immagini si possono caricare dopo aver creato la lezione.
        <?php endif; ?>
        I documenti da scaricare (PDF e altri) si aggiungono più in basso, tra i materiali.
    </p>

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
    <section class="materials-section lesson-form" id="materiali">
        <h3>Materiali scaricabili</h3>
        <p class="hint">PDF, documenti, presentazioni, fogli di calcolo, audio e archivi &mdash; max 50&nbsp;MB ciascuno.
            L'ordine di questo elenco è quello che vedono gli studenti.</p>

        <?php if (empty($materials)): ?>
            <p class="empty-state-small">Nessun materiale caricato.</p>
        <?php else: ?>
            <ul class="material-list material-list-editable">
                <?php foreach ($materials as $index => $material): ?>
                    <?php $extension = pathinfo((string) $material['file_name'], PATHINFO_EXTENSION); ?>
                    <li>
                        <span class="file-icon file-icon-<?= FileType::family($extension) ?>" aria-hidden="true">
                            <?= htmlspecialchars(FileType::badge($extension)) ?>
                        </span>
                        <span class="material-info">
                            <span class="material-name"><?= htmlspecialchars($material['file_name']) ?></span>
                            <span class="material-meta">
                                <?= htmlspecialchars(FileType::label($extension)) ?> ·
                                <?= FileType::humanSize((int) $material['file_size_bytes']) ?>
                            </span>
                        </span>
                        <span class="material-actions">
                            <form action="/lessons/<?= (int) $lesson['id'] ?>/materials/<?= (int) $material['id'] ?>/move" method="post">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="direction" value="up">
                                <button type="submit" class="icon-btn" title="Sposta su"
                                        aria-label="Sposta su" <?= $index === 0 ? 'disabled' : '' ?>>&uarr;</button>
                            </form>
                            <form action="/lessons/<?= (int) $lesson['id'] ?>/materials/<?= (int) $material['id'] ?>/move" method="post">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="direction" value="down">
                                <button type="submit" class="icon-btn" title="Sposta giù"
                                        aria-label="Sposta giù" <?= $index === count($materials) - 1 ? 'disabled' : '' ?>>&darr;</button>
                            </form>
                            <form action="/lessons/<?= (int) $lesson['id'] ?>/materials/<?= (int) $material['id'] ?>/delete"
                                  method="post" onsubmit="return confirm('Eliminare questo materiale?');">
                                <?= Csrf::field() ?>
                                <button type="submit" class="link-btn link-btn-danger">Elimina</button>
                            </form>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form action="/lessons/<?= (int) $lesson['id'] ?>/materials" method="post" enctype="multipart/form-data" class="stacked-form">
            <?= Csrf::field() ?>
            <label for="materials">Aggiungi materiali</label>
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

<script src="/assets/vendor/tinymce/tinymce.min.js"></script>
<script>
    // L'editor sta in casa: nessuna chiamata al cloud di TinyMCE e nessuna
    // chiave API. Il salvataggio ripassa comunque dal sanificatore lato server.
    tinymce.init({
        selector: '#content_html',
        language: 'it',
        base_url: '/assets/vendor/tinymce',
        license_key: 'gpl',
        promotion: false,
        branding: false,
        menubar: 'edit insert format table',
        plugins: 'advlist autolink autoresize charmap code fullscreen image link lists media searchreplace table wordcount',
        toolbar: 'undo redo | blocks | bold italic underline | bullist numlist | link image media table | removeformat code fullscreen',
        // Gli stessi blocchi accettati dal sanificatore: h1 no, e' il titolo della pagina.
        block_formats: 'Paragrafo=p; Titolo=h2; Sottotitolo=h3; Sotto-sottotitolo=h4; Preformattato=pre',
        autoresize_bottom_margin: 24,
        min_height: 420,
        content_css: '/assets/css/style.css',
        body_class: 'lesson-content',
        convert_urls: false,
        // Scheda "Carica" nella finestra dell'immagine: senza, l'unico modo di
        // inserire un'immagine sarebbe trascinarla dentro l'editor.
        image_uploadtab: true,
<?php if ($isEdit): ?>
        // Handler scritto a mano solo per allegare il token CSRF, che il Router
        // pretende su ogni POST.
        images_upload_handler: function (blobInfo) {
            return new Promise(function (resolve, reject) {
                var data = new FormData();
                data.append('file', blobInfo.blob(), blobInfo.filename());
                data.append('<?= App\Core\Csrf::FIELD ?>', '<?= htmlspecialchars(App\Core\Csrf::token(), ENT_QUOTES) ?>');

                fetch('/lessons/<?= (int) $lesson['id'] ?>/images', { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function (response) {
                        return response.json().then(function (body) {
                            if (!response.ok || !body.location) {
                                reject({ message: body.error || 'Caricamento non riuscito.', remove: true });
                                return;
                            }
                            resolve(body.location);
                        });
                    })
                    .catch(function () {
                        reject({ message: 'Caricamento non riuscito: server non raggiungibile.', remove: true });
                    });
            });
        },
        // Un'immagine incollata viene caricata come le altre, non incorporata
        // in base64: il sanificatore scarta data: e l'immagine sparirebbe.
        paste_data_images: true,
<?php else: ?>
        // Finche' la lezione non esiste non c'e' una cartella dove mettere le
        // immagini: meglio dirlo che lasciare fallire il caricamento.
        images_upload_handler: function () {
            return Promise.reject({
                message: 'Salva prima la lezione: le immagini si caricano dalla pagina di modifica.',
                remove: true
            });
        },
        paste_data_images: false,
<?php endif; ?>
    });
</script>
