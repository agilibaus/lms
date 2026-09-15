<?php

declare(strict_types=1);

/**
 * Campi condivisi tra "nuova domanda" e "modifica domanda".
 *
 * @var array|null $question
 * @var array $options  opzioni esistenti (vuoto per una nuova domanda)
 * @var int $maxOptions
 * @var string $formId  prefisso per gli id, evita collisioni quando i form coesistono
 */
$question = $question ?? null;
$options = $options ?? [];
$type = $question['question_type'] ?? 'single_choice';
$isTrueFalse = $type === 'true_false';

// Per le domande vero/falso le opzioni sono sempre due, nell'ordine Vero, Falso.
$trueFalseCorrect = 0;

if ($isTrueFalse) {
    foreach (array_values($options) as $index => $option) {
        if ((int) $option['is_correct'] === 1) {
            $trueFalseCorrect = $index;
        }
    }
}
?>
<label for="<?= $formId ?>-text">Testo della domanda</label>
<textarea id="<?= $formId ?>-text" name="question_text" rows="2" required><?= htmlspecialchars($question['question_text'] ?? '') ?></textarea>

<label for="<?= $formId ?>-type">Tipo</label>
<select id="<?= $formId ?>-type" name="question_type" data-question-type>
    <option value="single_choice" <?= $isTrueFalse ? '' : 'selected' ?>>Scelta singola</option>
    <option value="true_false" <?= $isTrueFalse ? 'selected' : '' ?>>Vero / Falso</option>
</select>

<fieldset class="option-set" data-options="single_choice" <?= $isTrueFalse ? 'hidden disabled' : '' ?>>
    <legend>Opzioni di risposta (seleziona quella corretta)</legend>
    <?php for ($i = 0; $i < $maxOptions; $i++): ?>
        <?php
        $existing = $isTrueFalse ? null : (array_values($options)[$i] ?? null);
        ?>
        <div class="option-row">
            <input type="radio" name="correct_option" value="<?= $i ?>"
                   aria-label="Risposta corretta: opzione <?= $i + 1 ?>"
                <?= $existing !== null && (int) $existing['is_correct'] === 1 ? 'checked' : '' ?>>
            <input type="text" name="options[]" maxlength="500" placeholder="Opzione <?= $i + 1 ?>"
                   value="<?= htmlspecialchars($existing['option_text'] ?? '') ?>">
        </div>
    <?php endfor; ?>
    <p class="form-hint">Compila almeno due opzioni. Le righe lasciate vuote vengono ignorate.</p>
</fieldset>

<fieldset class="option-set" data-options="true_false" <?= $isTrueFalse ? '' : 'hidden disabled' ?>>
    <legend>Risposta corretta</legend>
    <div class="option-row">
        <input type="radio" name="correct_option" value="0" <?= $isTrueFalse && $trueFalseCorrect === 0 ? 'checked' : '' ?>>
        <span>Vero</span>
    </div>
    <div class="option-row">
        <input type="radio" name="correct_option" value="1" <?= $isTrueFalse && $trueFalseCorrect === 1 ? 'checked' : '' ?>>
        <span>Falso</span>
    </div>
</fieldset>
