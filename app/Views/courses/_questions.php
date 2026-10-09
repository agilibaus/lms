<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Models\QuestionModel;

/**
 * «Fai una domanda all'esperto», in fondo al corso (07/10; dal 09/10 senza
 * l'archivio, che ha una pagina sua, «L'esperto risponde», nel menu, e con
 * l'esperto, l'admin, al posto del tutor).
 *
 * Ripiegata in una riga, che si apre con «Mostra» e si chiude con
 * «Nascondi» (0152): e' un `details`, senza JavaScript. Dentro il modulo per
 * chiedere, il collegamento all'archivio di questo corso (chi sta per
 * chiedere controlla se qualcuno l'ha gia' fatto) e «Le tue domande» con lo
 * stato. Solo per lo studente.
 *
 * Si apre da sola nei due casi in cui chi arriva deve vedere qualcosa che sta
 * dentro: dopo aver inviato una domanda («Le tue domande») e dopo un invio
 * respinto (il testo da correggere). Alla visita dopo riparte chiusa.
 *
 * @var array{mie: list<array<string,mixed>>, puoChiedere: bool, moduli: list<array<string,mixed>>} $domande
 * @var array<string, mixed> $course
 */

if (!$domande['puoChiedere']) {
    return;
}

$courseId = (int) $course['id'];
// Il testo e il modulo di una domanda respinta, per non doverli rifare. Su
// un modulo nuovo il modulo e' null, e il menu parte dalla prima voce.
$respinta = isset($_SESSION['question_old']);
$vecchio = $_SESSION['question_old'] ?? ['text' => '', 'module' => null];
unset($_SESSION['question_old']);
$aperta = $respinta || !empty($_SESSION['domande_aperte']);
unset($_SESSION['domande_aperte']);

$stati = [
    QuestionModel::PENDING => ['In attesa', 'qa-stato-attesa'],
    QuestionModel::PUBLISHED => ['Pubblicata', 'qa-stato-pubblicata'],
    QuestionModel::DISCARDED => ['Non pubblicata', 'qa-stato-scartata'],
];
?>
<section class="qa" id="domande" aria-labelledby="qa-titolo">
    <details class="qa-apri"<?= $aperta ? ' open' : '' ?>>
    <summary class="qa-riga">
        <span class="qa-riga-testo">
            <h2 id="qa-titolo">Fai una domanda all'esperto</h2>
        </span>
        <span class="qa-comando"><span class="qa-se-chiusa">Mostra</span><span class="qa-se-aperta">Nascondi</span></span>
    </summary>
    <div class="qa-contenuto">
        <form action="/courses/<?= $courseId ?>/domande" method="post" class="form">
            <?= Csrf::field() ?>

            <label for="qa-modulo">Su quale parte del corso</label>
            <select id="qa-modulo" name="module_id">
                <?php foreach ($domande['moduli'] as $m): ?>
                    <option value="<?= (int) $m['id'] ?>" <?= $vecchio['module'] !== null && (string) $vecchio['module'] === (string) $m['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $m['title']) ?>
                    </option>
                <?php endforeach; ?>
                <option value="" <?= $vecchio['module'] === '' ? 'selected' : '' ?>>Il corso in generale</option>
            </select>

            <?php
            /* Il contatore sopra l'angolo in alto a destra, sulla riga
               dell'etichetta (0154): lo stesso della presentazione del
               profilo, con lo stesso script. Parte dai caratteri gia'
               scritti, che ci sono dopo un invio respinto. */
            $restano = max(0, QuestionModel::MAX_QUESTION_CHARS - mb_strlen((string) $vecchio['text']));
            ?>
            <div class="quiz-open-wrap campo-contato">
                <div class="campo-contato-testa">
                    <label for="qa-domanda">La tua domanda</label>
                    <span class="quiz-open-count" id="qa-domanda-resta" data-max="<?= QuestionModel::MAX_QUESTION_CHARS ?>">
                        <?= $restano ?> <?= $restano === 1 ? 'carattere rimasto' : 'caratteri rimasti' ?>
                    </span>
                </div>
                <textarea id="qa-domanda" name="question" rows="3" required class="quiz-open-answer"
                          aria-describedby="qa-domanda-resta qa-domanda-aiuto"
                          maxlength="<?= QuestionModel::MAX_QUESTION_CHARS ?>"><?= htmlspecialchars((string) $vecchio['text']) ?></textarea>
            </div>
            <p class="form-hint" id="qa-domanda-aiuto">
                La legge l'esperto. Se la pubblica, compare con la risposta in «L'esperto risponde», con il tuo
                nome come hai scelto nel profilo. Prima di pubblicarla può correggerla.
            </p>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Invia la domanda</button>
            </div>
        </form>

        <?php /* Prima di chiedere, si controlla se qualcuno l'ha gia' fatto. */ ?>
        <p class="qa-vai-archivio">
            <a href="/domande-e-risposte?corso=<?= $courseId ?>">Leggi le risposte dell'esperto per questo corso</a>
        </p>

        <?php if ($domande['mie'] !== []): ?>
            <h3 class="qa-mie-titolo">Le tue domande</h3>
            <ul class="qa-mie" role="list">
                <?php foreach ($domande['mie'] as $q): ?>
                    <?php [$etichetta, $classe] = $stati[(string) $q['status']] ?? $stati[QuestionModel::PENDING]; ?>
                    <li>
                        <span class="qa-mie-testo"><?= htmlspecialchars((string) $q['question']) ?></span>
                        <span class="qa-stato <?= $classe ?>"><?= $etichetta ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    </details>
</section>
<script src="/assets/js/quiz-open-count.js"></script>
