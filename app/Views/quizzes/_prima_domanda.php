<?php

declare(strict_types=1);

/**
 * La prima domanda di un questionario nuovo (30/10, Alessandro), in
 * «Nuovo questionario» e in «Nuova verifica finale»: i campi di «Nuova
 * domanda», in un riquadro come «Video» nella lezione. Con la pagina
 * rifiutata tornano come erano stati scritti (`$riprendi`).
 *
 * @var array|null $riprendi
 * @var int $maxOptions
 */
?>
<fieldset class="video-fieldset prima-domanda" data-prima-domanda>
    <legend>Prima domanda</legend>
    <p class="form-hint prima-domanda-aiuto">
        Un questionario nasce con almeno una domanda. Le altre si aggiungono dopo, nella pagina del questionario.
    </p>
    <?php
    $formId = 'prima-domanda';
    $question = $riprendi['question'] ?? null;
    $options = $riprendi['options'] ?? [];
    require __DIR__ . '/_question_fields.php';
    ?>
</fieldset>
<?php require __DIR__ . '/_question_script.php'; ?>
<script src="/assets/js/prima-domanda.js"></script>
