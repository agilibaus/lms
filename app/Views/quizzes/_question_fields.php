<?php

declare(strict_types=1);

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
 */
$question = $question ?? null;
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
?>
<label for="<?= $formId ?>-text">Testo della domanda</label>
<textarea id="<?= $formId ?>-text" name="question_text" rows="2" required><?= htmlspecialchars($question['question_text'] ?? '') ?></textarea>

<label for="<?= $formId ?>-type">Tipo</label>
<select id="<?= $formId ?>-type" name="question_type" data-question-type>
    <?php foreach (QuizScoring::TIPI as $t): ?>
        <option value="<?= $t ?>" <?= $type === $t ? 'selected' : '' ?>>
            <?= htmlspecialchars(QuizScoring::etichetta($t)) ?>
        </option>
    <?php endforeach; ?>
</select>

<fieldset class="option-set" data-options="single_choice" <?= $type === 'single_choice' ? '' : 'hidden disabled' ?>>
    <legend>Opzioni di risposta (seleziona quella corretta)</legend>
    <?php for ($i = 0; $i < $maxOptions; $i++): ?>
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
    <p class="form-hint">Compila almeno due opzioni. Le righe lasciate vuote vengono ignorate.</p>
</fieldset>

<?php /* Caselle e non pallini: sono più di una. Il numero di risposte attese
         non si chiede qui — lo si conta dalle caselle spuntate, e lo
         studente lo vedrà scritto accanto alla domanda. */ ?>
<fieldset class="option-set" data-options="multiple_choice" <?= $type === 'multiple_choice' ? '' : 'hidden disabled' ?>>
    <legend>Opzioni di risposta (spunta tutte quelle corrette)</legend>
    <?php for ($i = 0; $i < $maxOptions; $i++): ?>
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
    <p class="form-hint">
        Compila almeno due opzioni e spuntane almeno una come corretta. Vale «tutto o niente»:
        lo studente prende il punto solo selezionando esattamente tutte le corrette e nessuna
        sbagliata, e accanto alla domanda leggerà quante ne deve scegliere.
    </p>
</fieldset>

<fieldset class="option-set" data-options="true_false" <?= $type === 'true_false' ? '' : 'hidden disabled' ?>>
    <legend>Risposta corretta</legend>
    <div class="option-row">
        <input type="radio" name="correct_option" value="0" <?= $type === 'true_false' && $trueFalseCorrect === 0 ? 'checked' : '' ?>>
        <span>Vero</span>
    </div>
    <div class="option-row">
        <input type="radio" name="correct_option" value="1" <?= $type === 'true_false' && $trueFalseCorrect === 1 ? 'checked' : '' ?>>
        <span>Falso</span>
    </div>
</fieldset>

<fieldset class="option-set" data-options="open" <?= $type === 'open' ? '' : 'hidden disabled' ?>>
    <legend>Risposta aperta</legend>
    <p class="form-hint">
        Non c'è niente da impostare: la risposta la scrive lo studente in un campo di testo.
        <strong>Non fa punteggio</strong> e non entra nel calcolo — né come domanda giusta né
        come sbagliata. La risposta viene salvata e si legge nell'esito del tentativo.
    </p>
</fieldset>
