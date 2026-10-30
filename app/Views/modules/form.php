<?php

declare(strict_types=1);

use App\Controllers\ModuleController;
use App\Core\TestoBreve;
use App\Core\Csrf;

/** @var array $course */
/** @var array|null $module */
/** @var array|null $riprendi */
/** @var bool $quizH5P il questionario del modulo e' un contenuto H5P */
$quizH5P ??= false;
/** @var bool $senzaQuestionario il modulo non ha ancora un questionario (30/10) */
$senzaQuestionario ??= false;
$isEdit = $module !== null;
// I valori dei campi: quelli del modulo, o quelli appena scritti se il
// salvataggio e' stato rifiutato per l'obiettivo oltre il limite (11/10).
$valori = array_merge($module ?? [], $riprendi ?? []);
// L'id del modulo, 0 quando si sta creando: la vista serve a entrambe le cose,
// e la riga esiste solo nel secondo caso.
$moduleId = (int) ($module['id'] ?? 0);
$action = $isEdit ? '/modules/' . $moduleId : '/courses/' . $course['id'] . '/modules';
?>
<div class="page-header">
    <a href="/courses/<?= (int) $course['id'] ?>" class="back-link"><span class="back-link-testo"><?= htmlspecialchars($course['title']) ?></span></a>
    <h1><?= $isEdit ? 'Modifica modulo' : 'Nuovo modulo' ?></h1>
</div>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<form action="<?= htmlspecialchars($action) ?>" method="post" class="stacked-form">
    <?= Csrf::field() ?>
    <label for="title">Titolo del modulo</label>
    <input type="text" id="title" name="title" required value="<?= htmlspecialchars((string) ($valori['title'] ?? '')) ?>">

    <?php /* La verifica finale (28/10, Alessandro): solo il questionario,
             sempre in fondo, una per corso; si apre con gli altri moduli
             completati. Accesa, «Questionario obbligatorio» sparisce
             (modulo-verifica.js): dopo la verifica non c'e' niente da
             bloccare, e il server lo salva comunque spento. */ ?>
    <?php /* Le due caselle (28/10, Alessandro): il nome in grassetto, la
             descrizione in testo normale (`.casella-descritta`). */ ?>
    <?php /* Dal 30/10 la verifica finale nasce dalla sua pagina, con il
             questionario: in «Nuovo modulo» la casella non c'e'. Qui si
             accende solo su un modulo che ha gia' un questionario, e
             accesa cancella l'obiettivo (la verifica non ne ha). */ ?>
    <?php if ($isEdit): ?>
        <?php
        $finale = !empty($module['is_final']);
        $spentaSenza = $senzaQuestionario && !$finale;
        $descritta = trim(($finale ? 'is_final_aiuto ' : '') . ($quizH5P ? 'quiz_h5p_aiuto ' : '')
            . ($spentaSenza ? 'verifica_senza_questionario_aiuto' : ''));
        ?>
        <label class="checkbox-label casella-descritta">
            <input type="checkbox" name="is_final" value="1" data-verifica-finale
                   <?= !empty($valori['is_final']) ? 'checked' : '' ?>
                   <?= ($quizH5P || $spentaSenza) && empty($valori['is_final']) ? 'disabled' : '' ?>
                   <?= $descritta !== '' ? 'aria-describedby="' . $descritta . '"' : '' ?>>
            <span><span class="casella-nome">Verifica finale</span>: il modulo contiene solo il questionario finale del corso, che si apre quando lo studente ha completato tutti gli altri moduli. Sta sempre in fondo al corso, e ce n’è una sola per corso.</span>
        </label>
        <?php if ($finale): ?>
            <p class="hint" id="is_final_aiuto">
                Spegnendola, il modulo resta senza lezioni e il suo questionario si apre subito a tutti gli studenti,
                anche a chi non ha completato gli altri moduli.
            </p>
        <?php endif; ?>
        <?php if ($spentaSenza): ?>
            <p class="hint" id="verifica_senza_questionario_aiuto"><?= htmlspecialchars(ModuleController::VERIFICA_SENZA_QUESTIONARIO) ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <label class="checkbox-label casella-descritta" data-questionario-obbligatorio>
        <input type="checkbox" name="quiz_required" value="1" <?= !empty($valori['quiz_required']) ? 'checked' : '' ?>
               <?= $quizH5P && empty($valori['quiz_required']) ? 'disabled' : '' ?>
               <?= $quizH5P ? 'aria-describedby="quiz_h5p_aiuto"' : '' ?>>
        <span><span class="casella-nome">Questionario obbligatorio</span>: i moduli successivi restano bloccati finché lo studente non supera il questionario di questo modulo</span>
    </label>
    <?php /* Il questionario del modulo e' H5P (29/10): le due caselle non si
             accendono (il server lo rifiuta comunque). Gia' accese da prima
             restano attive, per poterle spegnere. */ ?>
    <?php if ($quizH5P): ?>
        <p class="hint" id="quiz_h5p_aiuto">
            Il questionario di questo modulo è un contenuto H5P: non può essere obbligatorio né la verifica finale,
            che devono avere un questionario a domande.
        </p>
    <?php endif; ?>

    <?php /* L'obiettivo del modulo (11/10): facoltativo, 1.000 caratteri di
             testo visibile con il contatore, e qualche tag HTML. Si scrive
             qui; dove lo vedranno gli studenti non e' ancora deciso. */ ?>
    <?php /* La verifica finale non ha obiettivo (30/10): il campo non c'e', e
             accendendo la casella sparisce (modulo-verifica.js). */ ?>
    <?php if (empty($module['is_final'])): ?>
        <?= TestoBreve::campo('objective', 'Obiettivo del modulo', 'objective', ModuleController::MAX_OBJECTIVE_CHARS,
            (string) ($valori['objective'] ?? ''), 3) ?>
    <?php endif; ?>
    <script src="/assets/js/quiz-open-count.js"></script>
    <script src="/assets/js/modulo-verifica.js"></script>

    <?php /* Il valore in tabella e' "2026-11-15 09:00:00", il campo del
             browser vuole "2026-11-15T09:00": la T al posto dello spazio e
             senza i secondi. */ ?>
    <?php
    $availableFrom = $valori['available_from'] ?? null;
    $availableFromInput = $availableFrom === null
        ? ''
        : str_replace(' ', 'T', substr((string) $availableFrom, 0, 16));
    ?>
    <label for="available_from">Disponibile dal</label>
    <input type="datetime-local" id="available_from" name="available_from"
           value="<?= htmlspecialchars($availableFromInput) ?>"
           aria-describedby="available_from_aiuto">
    <p class="hint" id="available_from_aiuto">
        Lascia vuoto per tenere il modulo aperto da subito. Con una data, gli studenti
        vedono il titolo del modulo in grigio con l’indicazione di quando si aprirà, e
        non possono aprirne le lezioni, i questionari, i materiali né gli incontri dal vivo.
        È la data di tutto il corso: un gruppo può averne una sua, da «Date dei moduli» nella pagina del
        gruppo. Il giorno dell’apertura gli studenti ricevono un’email.
    </p>

    <?php /* «Annulla» accanto a «Crea modulo» e a «Salva» (14/10, Alessandro:
             ovunque si crea o si salva): non salva niente e riporta al corso. */ ?>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Salva' : 'Crea modulo' ?></button>
        <a href="/courses/<?= (int) $course['id'] ?>" class="btn btn-secondary">Annulla</a>
    </div>
</form>
