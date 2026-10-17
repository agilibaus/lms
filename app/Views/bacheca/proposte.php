<?php

declare(strict_types=1);

use App\Core\Bacheca;
use App\Core\Csrf;

/**
 * Le proposte degli studenti da valutare (17/10): il tutor vede quelle dei
 * suoi studenti, l'admin tutte. Per ognuna: chi la propone (per intero,
 * come lo staff vede sempre i suoi), il gruppo, il testo, e tre comandi:
 * «Pubblica» nella categoria scelta, «Correggi prima di pubblicare»,
 * «Non pubblicare». «Non pubblicare» e' un modulo a se': un comando
 * distruttivo dentro quello di «Pubblica» ne diventerebbe l'invio
 * predefinito.
 *
 * @var array<string, mixed> $corso
 * @var list<array<string, mixed>> $categorie
 * @var list<array<string, mixed>> $proposte
 */

$courseId = (int) $corso['id'];
?>
<?php require __DIR__ . '/../admin/_flash.php'; ?>
<div class="page-header">
    <a href="/bacheca?corso=<?= $courseId ?>" class="back-link"><span class="back-link-testo">Bacheca</span></a>
    <h1>Proposte da valutare</h1>
    <p class="page-subtitle">
        <?= $proposte === [] ? 'Nessuna proposta in attesa' : htmlspecialchars(ucfirst(Bacheca::daValutare(count($proposte)))) ?>
        nella bacheca del corso «<?= htmlspecialchars((string) $corso['title']) ?>».
    </p>
</div>

<?php foreach ($proposte as $p): ?>
    <?php $pid = (int) $p['id']; ?>
    <section class="card bacheca-proposta" id="proposta-<?= $pid ?>" aria-labelledby="proposta-<?= $pid ?>-titolo">
        <p class="bacheca-chi">
            Proposta da <?= htmlspecialchars($p['autore']) ?><?= $p['gruppo'] !== null ? ' · gruppo ' . htmlspecialchars($p['gruppo']) : '' ?>
            · <?= htmlspecialchars(date('d/m/Y', strtotime((string) $p['created_at']))) ?>
        </p>
        <div class="bacheca-testa">
            <h2 class="bacheca-titolo" id="proposta-<?= $pid ?>-titolo"><?= htmlspecialchars((string) $p['title']) ?></h2>
            <span class="badge badge-muted"><?= htmlspecialchars(Bacheca::TIPI[(string) $p['kind']] ?? 'Altro') ?></span>
        </div>
        <p class="bacheca-descrizione"><?= nl2br(htmlspecialchars((string) $p['description'])) ?></p>
        <?php if (!empty($p['url'])): ?>
            <p class="bacheca-link">
                <a href="<?= htmlspecialchars((string) $p['url'], ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars((string) $p['url']) ?><span class="sr-only"> (si apre in una nuova scheda)</span></a>
            </p>
        <?php endif; ?>

        <div class="bacheca-decidi">
            <form action="/bacheca/risorse/<?= $pid ?>/pubblica" method="post" class="form-inline">
                <?= Csrf::field() ?>
                <label for="proposta-<?= $pid ?>-categoria">Categoria</label>
                <select id="proposta-<?= $pid ?>-categoria" name="category_id">
                    <?php foreach ($categorie as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === (int) $p['category_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">Pubblica</button>
                <button type="reset" class="btn btn-secondary">Annulla</button>
            </form>
            <a class="link-btn" href="/bacheca/risorse/<?= $pid ?>/modifica">Correggi prima di pubblicare</a>
            <form action="/bacheca/risorse/<?= $pid ?>/scarta" method="post"
                  onsubmit="return confirm('Non pubblicare la proposta? Chi l’ha proposta la vedrà «Non pubblicata».');">
                <?= Csrf::field() ?>
                <button type="submit" class="link-btn link-btn-danger">Non pubblicare</button>
            </form>
        </div>
    </section>
<?php endforeach; ?>
