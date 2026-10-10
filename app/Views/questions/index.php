<?php

declare(strict_types=1);

use App\Core\CampoContato;
use App\Core\Csrf;
use App\Models\QuestionModel;

/**
 * Le domande in attesa, per chi risponde: l'esperto, cioe' l'admin (dal
 * 09/10; prima anche il tutor, per le domande dei suoi gruppi). Lo staff vede
 * sempre gli studenti per intero.
 *
 * Per ognuna: la domanda correggibile, come verra' pubblicata — per togliere
 * dettagli personali —, il modulo, la risposta, «Pubblica» e «Scarta».
 * «Scarta» e' un modulo a se' (un comando distruttivo dentro il modulo di
 * pubblicazione diventerebbe il suo invio predefinito, §5 del promemoria):
 * sta nella stessa riga di «Pubblica» con l'attributo `form`.
 *
 * @var list<array<string, mixed>> $domande
 * @var array<int, list<array<string, mixed>>> $moduli
 * @var bool $tutte
 * @var list<array<string, mixed>> $pubblicate
 * @var int $apri
 */

$quante = count($domande);
?>
<?php foreach ([['flash_success', 'alert-success'], ['flash_error', 'alert-error']] as [$chiave, $classe]): ?>
    <?php if (!empty($_SESSION[$chiave])): ?>
        <div class="alert <?= $classe ?>"><?= htmlspecialchars((string) $_SESSION[$chiave]) ?></div>
        <?php unset($_SESSION[$chiave]); ?>
    <?php endif; ?>
<?php endforeach; ?>
<div class="page-header">
    <h1>Domande</h1>
    <p class="page-subtitle">
        <?php if ($quante === 0): ?>
            Nessuna domanda in attesa.
        <?php else: ?>
            <?= $quante === 1 ? '1 in attesa' : $quante . ' in attesa' ?>,
            <?= $tutte ? 'da tutti i corsi.' : 'dagli studenti dei tuoi gruppi.' ?>
        <?php endif; ?>
    </p>
</div>

<?php foreach ($domande as $q): ?>
    <?php $qid = (int) $q['id']; ?>
    <section class="card qa-gestione" id="domanda-<?= $qid ?>" aria-labelledby="domanda-<?= $qid ?>-corso">
        <h2 id="domanda-<?= $qid ?>-corso"><?= htmlspecialchars((string) $q['course_title']) ?></h2>
        <p class="card-meta">
            Da <?= htmlspecialchars((string) ($q['student_name'] ?? 'uno studente non più iscritto')) ?><?php
            if (!empty($q['group_names'])): ?>, gruppo <?= htmlspecialchars((string) $q['group_names']) ?><?php endif; ?>
            · <?= date('j/n/Y H:i', strtotime((string) $q['created_at'])) ?>
        </p>

        <form action="/domande/<?= $qid ?>/pubblica" method="post" class="form">
            <?= Csrf::field() ?>

            <label for="domanda-<?= $qid ?>-modulo">Parte del corso</label>
            <select id="domanda-<?= $qid ?>-modulo" name="module_id">
                <?php foreach ($moduli[(int) $q['course_id']] ?? [] as $m): ?>
                    <option value="<?= (int) $m['id'] ?>" <?= (int) $m['id'] === (int) $q['module_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $m['title']) ?>
                    </option>
                <?php endforeach; ?>
                <option value="" <?= $q['module_id'] === null ? 'selected' : '' ?>>Il corso in generale</option>
            </select>

            <?= CampoContato::html('domanda-' . $qid . '-testo', 'Domanda, come verrà pubblicata', 'question',
                QuestionModel::MAX_QUESTION_CHARS, (string) $q['question'], 3, true) ?>
            <p class="form-hint">Puoi correggerla prima di pubblicarla, per esempio per togliere dettagli personali.</p>

            <?= CampoContato::html('domanda-' . $qid . '-risposta', 'Risposta', 'answer',
                QuestionModel::MAX_ANSWER_CHARS, '', 5, true) ?>

            <div class="form-actions qa-azioni">
                <button type="submit" class="btn btn-primary">Pubblica</button>
                <button type="submit" form="domanda-<?= $qid ?>-scarta" class="link-btn link-btn-danger">Scarta</button>
            </div>
        </form>

        <form id="domanda-<?= $qid ?>-scarta" action="/domande/<?= $qid ?>/scarta" method="post" class="qa-scarta"
              onsubmit="return confirm('Scartare questa domanda? Non verrà pubblicata, e lo studente la vedrà come «Non pubblicata».');">
            <?= Csrf::field() ?>
        </form>
    </section>
<?php endforeach; ?>

<?php
/*
 * Le domande pubblicate, da correggere o da togliere dall'archivio (08/10).
 * Divise per corso; ogni domanda e' un `details` chiuso, che si apre per
 * modificarla. Quella su cui si arriva dal «Modifica» dell'archivio del
 * corso (`?apri=`) e' gia' aperta. «Togli dall'archivio» la riporta a «Non
 * pubblicata»: lo studente la vede ancora fra le sue (Elena).
 *
 * @var list<array<string, mixed>> $pubblicate
 * @var int $apri
 */
$perCorso = [];
foreach ($pubblicate as $q) {
    $perCorso[(int) $q['course_id']]['titolo'] ??= (string) $q['course_title'];
    $perCorso[(int) $q['course_id']]['voci'][] = $q;
}
?>
<?php if ($pubblicate !== []): ?>
    <section class="qa-pubblicate" aria-labelledby="pubblicate-titolo">
        <h2 id="pubblicate-titolo">Pubblicate</h2>
        <p class="qa-intro">Apri una domanda per correggerla, o per toglierla dall'archivio del corso.</p>

        <?php foreach ($perCorso as $corso): ?>
            <h3 class="qa-modulo"><?= htmlspecialchars($corso['titolo']) ?></h3>
            <ul class="qa-elenco" role="list">
                <?php foreach ($corso['voci'] as $q): ?>
                    <?php $qid = (int) $q['id']; ?>
                    <li id="pubblicata-<?= $qid ?>">
                        <details class="qa-voce"<?= $apri === $qid ? ' open' : '' ?>>
                            <summary><?= htmlspecialchars((string) $q['question']) ?></summary>
                            <div class="qa-corpo">
                                <p class="qa-chi">
                                    Da <?= htmlspecialchars((string) ($q['student_name'] ?? 'uno studente non più iscritto')) ?>
                                    · risposta di <?= htmlspecialchars((string) ($q['answered_by_name'] ?? '—')) ?>
                                </p>

                                <form action="/domande/<?= $qid ?>/modifica" method="post" class="form">
                                    <?= Csrf::field() ?>

                                    <label for="pubblicata-<?= $qid ?>-modulo">Parte del corso</label>
                                    <select id="pubblicata-<?= $qid ?>-modulo" name="module_id">
                                        <?php foreach ($moduli[(int) $q['course_id']] ?? [] as $m): ?>
                                            <option value="<?= (int) $m['id'] ?>" <?= (int) $m['id'] === (int) $q['module_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars((string) $m['title']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="" <?= $q['module_id'] === null ? 'selected' : '' ?>>Il corso in generale</option>
                                    </select>

                                    <?= CampoContato::html('pubblicata-' . $qid . '-testo', 'Domanda', 'question',
                                        QuestionModel::MAX_QUESTION_CHARS, (string) $q['question'], 3, true) ?>

                                    <?= CampoContato::html('pubblicata-' . $qid . '-risposta', 'Risposta', 'answer',
                                        QuestionModel::MAX_ANSWER_CHARS, (string) $q['answer'], 5, true) ?>

                                    <div class="form-actions qa-azioni">
                                        <button type="submit" class="btn btn-primary">Salva</button>
                                        <button type="submit" form="pubblicata-<?= $qid ?>-togli" class="link-btn link-btn-danger">Togli dall'archivio</button>
                                    </div>
                                </form>

                                <form id="pubblicata-<?= $qid ?>-togli" action="/domande/<?= $qid ?>/togli" method="post" class="qa-scarta"
                                      onsubmit="return confirm('Togliere questa domanda dall\'archivio? Lo studente la vedrà come «Non pubblicata».');">
                                    <?= Csrf::field() ?>
                                </form>
                            </div>
                        </details>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
<script src="/assets/js/quiz-open-count.js"></script>
