<?php

declare(strict_types=1);

use App\Core\Bacheca;
use App\Core\CampoContato;
use App\Core\Csrf;
use App\Core\FileType;

/**
 * Una risorsa della bacheca: titolo, tipo, descrizione, il file o il link,
 * chi l'ha condivisa, «Questa me la tengo», i comandi di chi la gestisce e i
 * racconti «Come l'hanno utilizzata». Video e podcast si aprono dal loro
 * sito, in una scheda nuova: non si incorporano.
 *
 * @var array<string, mixed> $r preparata da BoardController::prepara()
 * @var bool $conCategoria
 * @var string $qui
 */

$rid = (int) $r['id'];
$torna = '<input type="hidden" name="torna" value="' . htmlspecialchars($qui, ENT_QUOTES) . '">';
?>
<li class="bacheca-risorsa<?= (int) $r['featured'] === 1 ? ' in-evidenza' : '' ?>" id="risorsa-<?= $rid ?>">
    <div class="bacheca-testa">
        <h2 class="bacheca-titolo"><?= htmlspecialchars((string) $r['title']) ?></h2>
        <span class="badge badge-muted"><?= htmlspecialchars(Bacheca::TIPI[(string) $r['kind']] ?? 'Altro') ?></span>
        <?php if ((int) $r['featured'] === 1): ?>
            <span class="badge bacheca-ev">In evidenza</span>
        <?php endif; ?>
        <?php if ($conCategoria): ?>
            <span class="bacheca-cat"><?= htmlspecialchars((string) $r['category_name']) ?></span>
        <?php endif; ?>
    </div>

    <p class="bacheca-descrizione"><?= nl2br(htmlspecialchars((string) $r['description'])) ?></p>

    <?php if (!empty($r['file_path'])): ?>
        <?php $ext = strtolower(pathinfo((string) $r['file_name'], PATHINFO_EXTENSION)); ?>
        <p class="bacheca-link">
            <a href="/bacheca/risorse/<?= $rid ?>/download"><?= htmlspecialchars((string) $r['file_name']) ?></a>
            <span class="bacheca-file-info">(<?= htmlspecialchars(FileType::label($ext)) ?>, <?= htmlspecialchars(FileType::humanSize((int) $r['file_size'])) ?>)</span>
        </p>
    <?php endif; ?>
    <?php if (!empty($r['url'])): ?>
        <p class="bacheca-link">
            <a href="<?= htmlspecialchars((string) $r['url'], ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars(Bacheca::etichettaLink((string) $r['kind'])) ?><span class="sr-only"> (si apre in una nuova scheda)</span></a>
        </p>
    <?php endif; ?>

    <div class="bacheca-azioni">
        <span class="bacheca-chi"><?= htmlspecialchars($r['chi']) ?> · <?= htmlspecialchars($r['quando']) ?></span>
        <form action="/bacheca/risorse/<?= $rid ?>/tengo" method="post">
            <?= Csrf::field() ?><?= $torna ?>
            <button type="submit" class="link-btn bacheca-tengo<?= $r['tenuta'] ? ' tenuta' : '' ?>" aria-pressed="<?= $r['tenuta'] ? 'true' : 'false' ?>">
                <?= $r['tenuta'] ? '✓ Te la tieni' : 'Questa me la tengo' ?>
            </button>
        </form>
        <?php if ($r['gestisce']): ?>
            <a class="link-btn" href="/bacheca/risorse/<?= $rid ?>/modifica">Modifica</a>
            <form action="/bacheca/risorse/<?= $rid ?>/evidenza" method="post">
                <?= Csrf::field() ?><?= $torna ?>
                <input type="hidden" name="si" value="<?= (int) $r['featured'] === 1 ? '0' : '1' ?>">
                <button type="submit" class="link-btn"><?= (int) $r['featured'] === 1 ? 'Togli dall’evidenza' : 'Metti in evidenza' ?></button>
            </form>
            <form action="/bacheca/risorse/<?= $rid ?>/togli" method="post"
                  onsubmit="return confirm('Togliere la risorsa dalla bacheca? Non si cancella: chi l’ha proposta la vede «Non pubblicata».');">
                <?= Csrf::field() ?>
                <button type="submit" class="link-btn link-btn-danger">Togli dalla bacheca</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($r['usi'] !== []): ?>
        <details class="qa-voce bacheca-usi">
            <summary>Come l’hanno utilizzata (<?= count($r['usi']) ?>)</summary>
            <div class="qa-corpo">
                <?php foreach ($r['usi'] as $u): ?>
                    <div class="bacheca-uso<?= $u['nascosto'] ? ' nascosto' : '' ?>">
                        <p><strong><?= htmlspecialchars($u['autore']) ?></strong> <?= nl2br(htmlspecialchars((string) $u['body'])) ?></p>
                        <?php if ($u['nascosto'] || $u['modera']): ?>
                            <div class="bacheca-uso-nota">
                                <?php if ($u['nascosto']): ?>
                                    <span class="badge badge-muted">Nascosto</span>
                                    <?= (int) $u['user_id'] === (int) \App\Auth\Auth::id() && !$u['modera'] ? 'Il tutor l’ha nascosto: lo vedi solo tu.' : '' ?>
                                <?php endif; ?>
                                <?php if ($u['modera']): ?>
                                    <form action="/bacheca/usi/<?= (int) $u['id'] ?>" method="post">
                                        <?= Csrf::field() ?><?= $torna ?>
                                        <input type="hidden" name="azione" value="<?= $u['nascosto'] ? 'rimetti' : 'nascondi' ?>">
                                        <button type="submit" class="link-btn"><?= $u['nascosto'] ? 'Rendi di nuovo visibile' : 'Nascondi' ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endif; ?>

    <?php if ($r['racconta']): ?>
        <?php $mio = $r['mio_uso']; ?>
        <details class="bacheca-racconta">
            <summary class="link-btn"><?= $mio === null ? 'Racconta come l’hai utilizzata' : 'Modifica il tuo racconto' ?></summary>
            <form action="/bacheca/risorse/<?= $rid ?>/uso" method="post" class="form">
                <?= Csrf::field() ?><?= $torna ?>
                <p class="form-hint bacheca-avvertenza">Non condividere dettagli privati sulla tua famiglia.</p>
                <?= CampoContato::html('uso-' . $rid, 'Come l’hai utilizzata', 'body', Bacheca::MAX_USO, (string) ($mio['body'] ?? ''), 3, true) ?>
                <div class="form-actions">
                    <button type="submit" class="btn btn-secondary"><?= $mio === null ? 'Pubblica il racconto' : 'Salva' ?></button>
                    <button type="reset" class="btn btn-secondary">Annulla</button>
                </div>
            </form>
        </details>
    <?php endif; ?>
</li>
