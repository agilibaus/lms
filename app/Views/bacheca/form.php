<?php

declare(strict_types=1);

use App\Core\Bacheca;
use App\Core\CampoContato;
use App\Core\Csrf;
use App\Core\FileType;

/**
 * Il modulo di una risorsa (17/10), in tre usi:
 *   - «Condividi una risorsa», dello studente: senza file, e sotto le sue
 *     proposte con lo stato;
 *   - «Nuova risorsa», di chi pubblica: con il file e «In evidenza»;
 *   - la modifica: di una pubblicata, o di una proposta da correggere prima
 *     di pubblicarla («Salva e pubblica»).
 *
 * @var array<string, mixed> $corso
 * @var list<array<string, mixed>> $categorie
 * @var array<string, mixed> $risorsa valori da mostrare (vuoto per una nuova)
 * @var string $azione
 * @var bool $staff
 * @var list<array<string, mixed>>|null $proposte
 */

$courseId = (int) $corso['id'];
$v = static fn (string $k): string => htmlspecialchars((string) ($risorsa[$k] ?? ''), ENT_QUOTES);
$esistente = isset($risorsa['id']);
$inAttesa = ($risorsa['status'] ?? '') === Bacheca::PENDING;
$accept = implode(',', array_map(static fn (string $e): string => '.' . $e, Bacheca::FILE_EXTENSIONS));
$stati = [
    Bacheca::PENDING => ['In attesa', 'bacheca-attesa'],
    Bacheca::PUBLISHED => ['Pubblicata', 'badge-success'],
    Bacheca::DISCARDED => ['Non pubblicata', 'badge-muted'],
];
?>
<?php require __DIR__ . '/../admin/_flash.php'; ?>
<div class="page-header">
    <a href="<?= $inAttesa ? '/bacheca/proposte?corso=' . $courseId : '/bacheca?corso=' . $courseId ?>" class="back-link"><span class="back-link-testo"><?= $inAttesa ? 'Proposte da valutare' : 'Bacheca' ?></span></a>
    <h1><?= htmlspecialchars((string) $pageTitle) ?></h1>
    <p class="page-subtitle">
        <?php if (!$staff): ?>
            La tua proposta arriva al tutor del tuo gruppo: la legge e, se va bene, la pubblica nella bacheca del
            corso «<?= htmlspecialchars((string) $corso['title']) ?>», per gli iscritti di tutti i gruppi.
        <?php else: ?>
            Bacheca del corso «<?= htmlspecialchars((string) $corso['title']) ?>».
        <?php endif; ?>
    </p>
</div>

<?php if ($categorie === []): ?>
    <p class="empty-state">La bacheca di questo corso non ha ancora categorie: le crea l'admin da «Modifica corso».</p>
<?php else: ?>
    <section class="card" aria-label="<?= htmlspecialchars((string) $pageTitle, ENT_QUOTES) ?>">
        <form action="<?= htmlspecialchars($azione, ENT_QUOTES) ?>" method="post" class="form"<?= $staff ? ' enctype="multipart/form-data"' : '' ?>>
            <?= Csrf::field() ?>

            <label for="bacheca-titolo">Titolo</label>
            <input type="text" id="bacheca-titolo" name="title" required maxlength="<?= Bacheca::MAX_TITOLO ?>" value="<?= $v('title') ?>">

            <label for="bacheca-tipo">Tipo</label>
            <select id="bacheca-tipo" name="kind">
                <?php foreach (Bacheca::TIPI as $chiave => $nome): ?>
                    <option value="<?= $chiave ?>" <?= ($risorsa['kind'] ?? 'scheda') === $chiave ? 'selected' : '' ?>><?= $nome ?></option>
                <?php endforeach; ?>
            </select>

            <label for="bacheca-categoria">Categoria</label>
            <select id="bacheca-categoria" name="category_id" <?= $staff ? '' : 'aria-describedby="bacheca-categoria-aiuto"' ?>>
                <?php foreach ($categorie as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= (int) ($risorsa['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (!$staff): ?>
                <p class="form-hint" id="bacheca-categoria-aiuto">Scegli quella che ti sembra più adatta: il tutor la può cambiare.</p>
            <?php endif; ?>

            <label for="bacheca-link">Link (facoltativo)</label>
            <input type="text" inputmode="url" id="bacheca-link" name="url" maxlength="<?= Bacheca::MAX_LINK ?>" value="<?= $v('url') ?>"
                   aria-describedby="bacheca-link-aiuto" autocomplete="off">
            <p class="form-hint" id="bacheca-link-aiuto">Serve per video e podcast, che si aprono dal loro sito: non si caricano qui.</p>

            <?= CampoContato::html('bacheca-descrizione', 'Descrizione', 'description', Bacheca::MAX_DESCRIZIONE,
                (string) ($risorsa['description'] ?? ''), 6, true) ?>
            <p class="form-hint">Che cos’è, a cosa serve, quando può essere utile, come usarla.</p>

            <?php if ($staff): ?>
                <label for="bacheca-file">File (facoltativo)</label>
                <?php if (!empty($risorsa['file_path'])): ?>
                    <?php $ext = strtolower(pathinfo((string) $risorsa['file_name'], PATHINFO_EXTENSION)); ?>
                    <p class="form-hint">
                        Adesso: <a href="/bacheca/risorse/<?= (int) $risorsa['id'] ?>/download"><?= $v('file_name') ?></a>
                        (<?= htmlspecialchars(FileType::label($ext)) ?>, <?= htmlspecialchars(FileType::humanSize((int) $risorsa['file_size'])) ?>).
                        Un file nuovo lo sostituisce.
                    </p>
                <?php endif; ?>
                <input type="file" id="bacheca-file" name="file" accept="<?= $accept ?>" aria-describedby="bacheca-file-aiuto">
                <p class="form-hint" id="bacheca-file-aiuto">
                    PDF, Word, PowerPoint, Excel, audio, ZIP o testo, fino a <?= (int) (Bacheca::FILE_MAX_BYTES / 1024 / 1024) ?> MB.
                </p>
                <?php if (!empty($risorsa['file_path'])): ?>
                    <label class="checkbox-label"><input type="checkbox" name="remove_file" value="1"> Togli il file</label>
                <?php endif; ?>

                <?php if (!$inAttesa): ?>
                    <label class="checkbox-label">
                        <input type="checkbox" name="featured" value="1" <?= (int) ($risorsa['featured'] ?? 0) === 1 ? 'checked' : '' ?>>
                        In evidenza: sta in cima alla sua categoria
                    </label>
                <?php endif; ?>
            <?php endif; ?>

            <div class="form-actions">
                <?php if (!$staff): ?>
                    <button type="submit" class="btn btn-primary">Invia la proposta</button>
                <?php elseif ($inAttesa): ?>
                    <button type="submit" name="publish" value="1" class="btn btn-primary">Salva e pubblica</button>
                    <button type="submit" class="btn btn-secondary">Salva senza pubblicare</button>
                <?php else: ?>
                    <button type="submit" class="btn btn-primary"><?= $esistente ? 'Salva' : 'Pubblica' ?></button>
                <?php endif; ?>
                <a href="<?= $inAttesa ? '/bacheca/proposte?corso=' . $courseId : '/bacheca?corso=' . $courseId ?>" class="btn btn-secondary">Annulla</a>
            </div>
        </form>
    </section>
<?php endif; ?>

<?php if (isset($proposte)): ?>
    <section class="card bacheca-proposte" aria-labelledby="bacheca-proposte-titolo">
        <h2 id="bacheca-proposte-titolo">Le tue proposte</h2>
        <?php if ($proposte === []): ?>
            <p class="empty-state-small">Non hai ancora proposto nessuna risorsa in questo corso.</p>
        <?php else: ?>
            <ul class="bacheca-mie" role="list">
                <?php foreach ($proposte as $p): ?>
                    <?php [$etichetta, $classe] = $stati[(string) $p['status']] ?? $stati[Bacheca::PENDING]; ?>
                    <li><span><?= htmlspecialchars((string) $p['title']) ?></span> <span class="badge <?= $classe ?>"><?= $etichetta ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>
<script src="/assets/js/quiz-open-count.js"></script>
