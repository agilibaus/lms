<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Models\QuestionModel;

/**
 * Le domande in attesa, per chi risponde (07/10). Il tutor vede quelle degli
 * studenti dei suoi gruppi; l'admin tutte, e per ognuna a quale tutor e'
 * assegnata (Elena). Lo staff vede sempre gli studenti per intero.
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
            <?php if ($tutte): ?>
                <br>Assegnata a: <?= $q['tutor_name'] !== null
                    ? htmlspecialchars((string) $q['tutor_name'])
                    : 'nessun tutor (lo studente non è in un gruppo con un tutor)' ?>
            <?php endif; ?>
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

            <label for="domanda-<?= $qid ?>-testo">Domanda, come verrà pubblicata</label>
            <textarea id="domanda-<?= $qid ?>-testo" name="question" rows="3" required
                      maxlength="<?= QuestionModel::MAX_QUESTION_CHARS ?>"><?= htmlspecialchars((string) $q['question']) ?></textarea>
            <p class="form-hint">Puoi correggerla prima di pubblicarla, per esempio per togliere dettagli personali.</p>

            <label for="domanda-<?= $qid ?>-risposta">Risposta</label>
            <textarea id="domanda-<?= $qid ?>-risposta" name="answer" rows="5" required
                      maxlength="<?= QuestionModel::MAX_ANSWER_CHARS ?>"></textarea>

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
