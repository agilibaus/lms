<?php

declare(strict_types=1);

use App\Core\CourseCover;
use App\Core\Csrf;

/**
 * Copertina del corso: caricamento, testo alternativo, rimozione.
 *
 * Form separato da quello dei dati perche' porta un file: tenerli insieme
 * significherebbe che ogni salvataggio del titolo passa da un upload.
 *
 * @var array $course
 */

$courseId = (int) $course['id'];
$coverUrl = CourseCover::url($course, false);
$isExternal = $coverUrl !== null && CourseCover::isExternalUrl($coverUrl);
?>
<section class="card">
    <h2>Copertina</h2>
    <p class="card-meta">
        Compare nell'elenco dei corsi, nel catalogo e in cima a questa pagina.
        Viene ritagliata al centro in formato 16:9: scegli un'immagine dove
        quello che conta non sta sui bordi.
    </p>

    <?php if ($coverUrl !== null): ?>
        <div class="cover-preview">
            <img src="<?= htmlspecialchars($coverUrl) ?>" alt="<?= htmlspecialchars(CourseCover::altFor($course)) ?>">
        </div>

        <?php if ($isExternal): ?>
            <p class="form-hint">
                Questa copertina e' un indirizzo esterno impostato a mano nel database.
                Caricando un'immagine viene sostituita da un file sul server.
            </p>
        <?php endif; ?>
    <?php else: ?>
        <p class="empty-state">Nessuna copertina: nell'elenco il corso mostra un riquadro con le sue iniziali.</p>
    <?php endif; ?>

    <form action="/admin/courses/<?= $courseId ?>/copertina" method="post" class="form" enctype="multipart/form-data">
        <?= Csrf::field() ?>

        <label for="cover"><?= $coverUrl !== null ? 'Sostituisci immagine' : 'Immagine' ?></label>
        <input type="file" id="cover" name="cover" accept="image/jpeg,image/png,image/gif,image/webp">
        <p class="form-hint">JPG, PNG, GIF o WebP, fino a 8 MB. Larghezza consigliata almeno 1280 px.</p>

        <label for="cover_alt">Testo alternativo</label>
        <input type="text" id="cover_alt" name="cover_alt" maxlength="255"
               value="<?= htmlspecialchars((string) ($course['cover_alt'] ?? '')) ?>"
               placeholder="Descrivi l'immagine a chi non la vede">
        <p class="form-hint">
            Lo leggono i lettori di schermo e compare se l'immagine non si carica.
            Descrivi cosa si vede, non ripetere il titolo del corso: se lo lasci vuoto viene usato il titolo.
        </p>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Salva copertina</button>
        </div>
    </form>

    <?php if ($coverUrl !== null): ?>
        <form action="/admin/courses/<?= $courseId ?>/copertina/elimina" method="post"
              onsubmit="return confirm('Rimuovere la copertina di questo corso?');">
            <?= Csrf::field() ?>
            <button type="submit" class="link-btn link-btn-danger">Rimuovi copertina</button>
        </form>
    <?php endif; ?>
</section>
