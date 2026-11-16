<?php

declare(strict_types=1);

use App\Controllers\QuizController;
use App\Core\CampoContato;
use App\Core\QuizScoring;

/**
 * Campi condivisi tra "nuova domanda" e "modifica domanda".
 *
 * I quattro tipi hanno bisogno di campi diversi, e i gruppi di campi stanno
 * tutti nel modulo: lo script mostra quello giusto e **disabilita** gli
 * altri, così non vengono inviati. Senza JavaScript resta utilizzabile il
 * gruppo del tipo già selezionato — per una domanda nuova, la scelta
 * singola. È una limitazione vecchia, non introdotta qui, ed è scritta anche
 * nel promemoria.
 *
 * @var array|null $question
 * @var array $options  opzioni esistenti (vuoto per una nuova domanda)
 * @var int $maxOptions
 * @var string $formId  prefisso per gli id, evita collisioni quando i form coesistono
 * @var int $tentativi  i tentativi del questionario (solo nella modifica)
 *
 * CON DEI TENTATIVI (16/11, Alessandro) la domanda si corregge ma non si
 * trasforma: il tipo e' scritto e non si sceglie, compaiono solo le opzioni
 * che ci sono (senza righe vuote da riempire), e la frase sotto le opzioni
 * dice che cosa si puo' cambiare e che le corrette nuove ricalcolano i voti.
 */
$question = $question ?? null;
$tentativi = (int) ($tentativi ?? 0);
$bloccata = $question !== null && $tentativi > 0;
$type = (string) ($question['question_type'] ?? 'single_choice');

if (!QuizScoring::esiste($type)) {
    $type = 'single_choice';
}

$valori = array_values($options);

// Per le domande vero/falso le opzioni sono sempre due, nell'ordine Vero, Falso.
$trueFalseCorrect = 0;

if ($type === 'true_false') {
    foreach ($valori as $index => $option) {
        if ((int) $option['is_correct'] === 1) {
            $trueFalseCorrect = $index;
        }
    }
}

/* Le opzioni esistenti si mostrano nel gruppo del tipo a cui appartengono.
   Negli altri gruppi le caselle partono vuote: travasare le opzioni di una
   scelta singola dentro la multipla sembrerebbe un servizio, ma porterebbe
   una risposta corretta sola in un tipo che ne vuole piu' d'una. */
$perTipo = static fn (string $tipo): array => $type === $tipo ? $valori : [];

// Le righe delle opzioni: tutte quelle possibili, o con dei tentativi solo
// quelle che ci sono.
$righe = $bloccata ? count($valori) : $maxOptions;
$mostra = static fn (string $tipo): bool => !$bloccata || $type === $tipo;
$giaRisposte = 'Questa domanda ha già le risposte di ' . ($tentativi === 1 ? '1 tentativo' : $tentativi . ' tentativi') . ': ';
$ricalcolo = static fn (bool $piu): string => '<strong>Se cambi ' . ($piu ? 'le risposte corrette' : 'la risposta corretta')
    . ', i voti dei tentativi già svolti vengono ricalcolati</strong>: prima di salvare puoi vedere quanti cambiano.';
?>
<?= CampoContato::html($formId . '-text', 'Testo della domanda', 'question_text', QuizController::MAX_QUESTION_CHARS,
    (string) ($question['question_text'] ?? ''), 2, true) ?>
<?php /* Lo script collega ogni campo una volta sola: la pagina del
         questionario include questi campi una volta per domanda. */ ?>
<script src="/assets/js/quiz-open-count.js"></script>

<?php if ($bloccata): ?>
    <?php /* Scritto e non scelto: con dei tentativi il tipo non cambia, e un
             menu con una voce sola sarebbe un comando che non fa niente. */ ?>
    <p><strong>Tipo:</strong> <?= htmlspecialchars(QuizScoring::etichetta($type)) ?></p>
    <input type="hidden" name="question_type" value="<?= htmlspecialchars($type) ?>">
<?php else: ?>
    <label for="<?= $formId ?>-type">Tipo</label>
    <select id="<?= $formId ?>-type" name="question_type" data-question-type>
        <?php foreach (QuizScoring::TIPI as $t): ?>
            <option value="<?= $t ?>" <?= $type === $t ? 'selected' : '' ?>>
                <?= htmlspecialchars(QuizScoring::etichetta($t)) ?>
            </option>
        <?php endforeach; ?>
    </select>
<?php endif; ?>

<?php if ($mostra('single_choice')): ?>
<fieldset class="option-set" data-options="single_choice" <?= $type === 'single_choice' ? '' : 'hidden disabled' ?>>
    <legend>Opzioni di risposta (seleziona quella corretta)</legend>
    <?php for ($i = 0; $i < ($type === 'single_choice' ? $righe : $maxOptions); $i++): ?>
        <?php $existing = $perTipo('single_choice')[$i] ?? null; ?>
        <div class="option-row">
            <input type="radio" name="correct_option" value="<?= $i ?>"
                   aria-label="Risposta corretta: opzione <?= $i + 1 ?>"
                <?= $existing !== null && (int) $existing['is_correct'] === 1 ? 'checked' : '' ?>>
            <input type="text" name="options[]" maxlength="500" placeholder="Opzione <?= $i + 1 ?>"
                   aria-label="Testo dell'opzione <?= $i + 1 ?>"
                   value="<?= htmlspecialchars($existing['option_text'] ?? '') ?>">
        </div>
    <?php endfor; ?>
    <?php if ($bloccata): ?>
        <p class="form-hint">
            <?= $giaRisposte ?>puoi correggere i testi e cambiare la risposta corretta, non aggiungere o togliere
            opzioni. <?= $ricalcolo(false) ?>
        </p>
    <?php else: ?>
        <p class="form-hint">Compila almeno due opzioni. Le righe lasciate vuote vengono ignorate.</p>
    <?php endif; ?>
</fieldset>
<?php endif; ?>

<?php /* Caselle e non pallini: sono più di una. Il numero di risposte attese
         non si chiede qui — lo si conta dalle caselle spuntate, e lo
         studente lo vedrà scritto accanto alla domanda. */ ?>
<?php if ($mostra('multiple_choice')): ?>
<fieldset class="option-set" data-options="multiple_choice" <?= $type === 'multiple_choice' ? '' : 'hidden disabled' ?>>
    <legend>Opzioni di risposta (spunta tutte quelle corrette)</legend>
    <?php for ($i = 0; $i < ($type === 'multiple_choice' ? $righe : $maxOptions); $i++): ?>
        <?php $existing = $perTipo('multiple_choice')[$i] ?? null; ?>
        <div class="option-row">
            <input type="checkbox" name="correct_options[]" value="<?= $i ?>"
                   aria-label="L'opzione <?= $i + 1 ?> è corretta"
                <?= $existing !== null && (int) $existing['is_correct'] === 1 ? 'checked' : '' ?>>
            <input type="text" name="options[]" maxlength="500" placeholder="Opzione <?= $i + 1 ?>"
                   aria-label="Testo dell'opzione <?= $i + 1 ?>"
                   value="<?= htmlspecialchars($existing['option_text'] ?? '') ?>">
        </div>
    <?php endfor; ?>
    <?php if ($bloccata): ?>
        <p class="form-hint">
            <?= $giaRisposte ?>puoi correggere i testi e cambiare le risposte corrette, non aggiungere o togliere
            opzioni. La domanda conta come giusta solo se lo studente sceglie tutte le risposte corrette e
            nessuna sbagliata: se ne sceglie solo una parte, o ne aggiunge una sbagliata, conta come
            sbagliata. <?= $ricalcolo(true) ?>
        </p>
    <?php else: ?>
        <p class="form-hint">
            Compila almeno due opzioni e spuntane almeno una come corretta. Vale «tutto o niente»:
            lo studente prende il punto solo selezionando esattamente tutte le corrette e nessuna
            sbagliata, e accanto alla domanda leggerà quante ne deve scegliere.
        </p>
    <?php endif; ?>
</fieldset>
<?php endif; ?>

<?php if ($mostra('true_false')): ?>
<fieldset class="option-set" data-options="true_false" <?= $type === 'true_false' ? '' : 'hidden disabled' ?>>
    <legend>Risposta corretta</legend>
    <?php /* Un'etichetta e non un `span`: e' cio' che fa annunciare «Vero» e
             «Falso» a un lettore di schermo e che permette di scegliere
             toccando la parola invece di centrare il pallino. Le opzioni
             degli altri tipi hanno un campo di testo accanto e si nominano
             con `aria-label`; qui la parola c'e' gia', e basta legarla. */ ?>
    <label class="option-row">
        <input type="radio" name="correct_option" value="0" <?= $type === 'true_false' && $trueFalseCorrect === 0 ? 'checked' : '' ?>>
        <span>Vero</span>
    </label>
    <label class="option-row">
        <input type="radio" name="correct_option" value="1" <?= $type === 'true_false' && $trueFalseCorrect === 1 ? 'checked' : '' ?>>
        <span>Falso</span>
    </label>
    <?php if ($bloccata): ?>
        <p class="form-hint"><?= $giaRisposte ?>puoi correggere il testo e cambiare la risposta corretta. <?= $ricalcolo(false) ?></p>
    <?php endif; ?>
</fieldset>
<?php endif; ?>

<?php if ($mostra('open')): ?>
<fieldset class="option-set" data-options="open" <?= $type === 'open' ? '' : 'hidden disabled' ?>>
    <legend>Risposta aperta</legend>
    <?php if ($bloccata): ?>
        <p class="form-hint">
            <?= $giaRisposte ?>puoi correggere il testo, non cambiare il tipo. Non fa punteggio, quindi non ci
            sono voti da ricalcolare.
        </p>
    <?php else: ?>
    <p class="form-hint">
        Non c'è niente da impostare: la risposta la scrive lo studente in un campo di testo.
        <strong>Non fa punteggio</strong> e non entra nel calcolo — né come domanda giusta né
        come sbagliata. La risposta viene salvata e si legge nell'esito del tentativo.
    </p>
    <?php endif; ?>
</fieldset>
<?php endif; ?>
