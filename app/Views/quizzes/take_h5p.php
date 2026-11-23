<?php

declare(strict_types=1);

use App\Core\Ritorno;
use App\Core\Quando;
use App\Auth\CourseRights;
use App\Core\Csrf;
use App\Core\H5P;
use App\Core\Icona;
use App\Core\QuizScoring;

/**
 * Lo svolgimento di un questionario H5P (22/10): l'esercizio nel
 * riproduttore h5p-standalone, dentro un iframe (decisione del 22/10, dopo
 * la prova con «stop.h5p»: lo studente vede la stessa cosa e gli stili di
 * Pistacchio non entrano nell'esercizio). Il punteggio lo manda
 * `/assets/js/h5p-questionario.js` a ogni «Verifica»; sotto l'esercizio la
 * frase che dice se il tentativo e' stato salvato.
 *
 * @var array $quiz
 * @var array $module
 * @var array|null $course
 * @var array $attempts
 * @var array|null $best
 * @var bool $concluso superato al 100% dallo studente: esercizio spento (23/10)
 */

$id = (int) $quiz['id'];
?>
<div class="page-header">
    <a href="/courses/<?= (int) $module['course_id'] ?>" class="back-link"><span class="back-link-testo"><?= htmlspecialchars($course['title'] ?? 'Corso') ?></span></a>
    <h1><?= htmlspecialchars($quiz['title']) ?></h1>
    <p class="page-subtitle">
        Modulo: <?= htmlspecialchars($module['title']) ?> ·
        soglia di superamento <?= (int) $quiz['passing_score_pct'] ?>%<?= $concluso ? '' : ' · tentativi illimitati' ?>
    </p>
</div>

<?php if ($course !== null && CourseRights::canEdit((int) $course['id'])): ?>
    <p><a href="<?= htmlspecialchars(Ritorno::link('/quizzes/' . (int) $quiz['id'] . '/edit', 'questionario', (int) $quiz['id'])) ?>" class="btn btn-primary btn-con-icona"><?= Icona::svg('pencil') ?>Modifica questionario</a></p>
<?php endif; ?>

<?php if ($concluso): ?>
    <?php /* Superato al 100% (23/10, Alessandro): il questionario resta
             visibile ma spento, e non si rifà. */ ?>
    <div class="alert alert-success quiz-concluso-avviso">
        <?= Icona::svg('check-circle-fill') ?><strong><?= htmlspecialchars(QuizScoring::CONCLUSO) ?></strong>
    </div>
<?php elseif ($best !== null): ?>
    <div class="alert <?= $best['passed'] ? 'alert-success' : 'alert-warning' ?>">
        Miglior risultato: <?= number_format((float) $best['score_pct'], 0) ?>%
        (<?= $best['passed'] ? 'superato' : 'non superato' ?>) su <?= (int) $best['attempts'] === 1 ? '1 tentativo' : (int) $best['attempts'] . ' tentativi' ?>.
    </div>
<?php endif; ?>

<section class="h5p-questionario" aria-label="Esercizio">
    <?php /* Concluso: l'esercizio si vede ma e' spento (`inert`: niente
             mouse, niente tastiera, anche dentro l'iframe), e lo script non
             manda punteggi. */ ?>
    <div id="h5p-contenitore" class="h5p-contenitore<?= $concluso ? ' h5p-concluso' : '' ?>"
         <?= $concluso ? 'inert data-concluso' : '' ?>
         data-percorso="/quizzes/<?= $id ?>/h5p"
         data-salva="/quizzes/<?= $id ?>/h5p/tentativo"
         data-token="<?= htmlspecialchars(Csrf::token()) ?>"
         data-campo="<?= htmlspecialchars(Csrf::FIELD) ?>"
         data-titolo="<?= htmlspecialchars('Esercizio interattivo: ' . $quiz['title']) ?>"></div>
    <noscript><p class="empty-state">Per svolgere questo esercizio serve JavaScript attivo nel browser.</p></noscript>
    <p class="h5p-esito" id="h5p-esito" role="status"></p>
</section>

<?php if ($attempts !== []): ?>
    <section class="card">
        <h2>I tuoi tentativi</h2>
        <?php /* Sul telefono la tabella usciva dallo schermo e «Dettaglio»
                 restava tagliato (23/11): sotto i 50 rem le righe diventano
                 schede, come nei certificati. I `role` servono perche'
                 cambiando il `display` la tabella perderebbe le sue semantiche. */ ?>
        <div class="tabella-schede">
        <table class="data-table" role="table">
            <thead role="rowgroup">
            <tr role="row">
                <th scope="col" role="columnheader">Data</th>
                <th scope="col" role="columnheader">Punteggio</th>
                <th scope="col" role="columnheader">Esito</th>
                <th scope="col" role="columnheader"><span class="sr-only">Azioni</span></th>
            </tr>
            </thead>
            <tbody role="rowgroup">
            <?php foreach ($attempts as $attempt): ?>
                <tr role="row">
                    <td role="cell" data-label="Data"><?= htmlspecialchars(Quando::dataOra((string) $attempt['attempted_at'])) ?></td>
                    <td role="cell" data-label="Punteggio">
                        <?= number_format((float) $attempt['score_pct'], 0) ?>%
                        <?php if (($attempt['h5p_raw'] ?? null) !== null && ($attempt['h5p_max'] ?? null) !== null): ?>
                            <span class="cell-sub"><?= htmlspecialchars(H5P::punti((float) $attempt['h5p_raw'], (float) $attempt['h5p_max'])) ?></span>
                        <?php endif; ?>
                    </td>
                    <td role="cell" data-label="Esito"><?= (int) $attempt['passed'] === 1 ? 'Superato' : 'Non superato' ?></td>
                    <td role="cell" class="row-actions"><a href="/attempts/<?= (int) $attempt['id'] ?>">Dettaglio</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
<?php endif; ?>

<script src="/assets/h5p/main.bundle.js"></script>
<script src="/assets/js/h5p-questionario.js"></script>
