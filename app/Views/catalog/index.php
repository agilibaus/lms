<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Core\CorsiAPagamento;
use App\Core\CourseCover;
use App\Core\Csrf;
use App\Core\TestoBreve;
use App\Models\CourseModel;
use App\Models\EnrollmentRequestModel;

/** @var array $courses */
?>
<div class="page-header">
    <h1>Esplora corsi</h1>
    <?php /* «I miei corsi» e' il nome che la voce ha nel menu dello studente,
             che e' l'unico a vedere questa pagina: «Corsi» e' come la stessa
             voce si chiama per admin, tutor e assistente, e qui mandava a
             cercare una pagina che con quel nome lo studente non ha. */ ?>
    <p class="page-subtitle">I corsi a cui puoi iscriverti. Quelli che segui già sono nella pagina I miei corsi.</p>
</div>

<?php require __DIR__ . '/../admin/_flash.php'; ?>

<?php if ($courses === []): ?>
    <p class="empty-state">
        Al momento non ci sono corsi disponibili per l'iscrizione autonoma.
        Se aspetti di essere iscritto a un corso riservato, ci penserà chi lo gestisce.
    </p>
<?php else: ?>
    <div class="catalog-list">
        <?php foreach ($courses as $course): ?>
            <?php
            $id = (int) $course['id'];
            $pending = ($course['request_status'] ?? null) === 'pending';
            $rejected = ($course['request_status'] ?? null) === 'rejected';
            ?>
            <section class="card catalog-card">
                <?php $cover = CourseCover::url($course); ?>
                <div class="catalog-card-cover">
                    <?php if ($cover !== null): ?>
                        <img src="<?= htmlspecialchars($cover) ?>" alt="<?= htmlspecialchars(CourseCover::altFor($course)) ?>" loading="lazy">
                    <?php else: ?>
                        <?php require __DIR__ . '/../courses/_cover_placeholder.php'; ?>
                    <?php endif; ?>
                </div>

                <?php $pagato = $course['enrollment_mode'] === CorsiAPagamento::MODALITA; ?>
                <div class="card-head">
                    <h2><?= htmlspecialchars((string) $course['title']) ?></h2>
                    <?php if (!$pagato): ?>
                        <span class="badge <?= $course['enrollment_mode'] === 'open' ? 'badge-success' : '' ?>">
                            <?= htmlspecialchars(CourseModel::enrollmentLabel($course['enrollment_mode'])) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php $descrizione = (string) ($course['description'] ?? ''); ?>
                <?php if ($descrizione !== '' && !CourseModel::estrattoTaglia($descrizione)): ?>
                    <div class="card-meta testo-breve"><?= TestoBreve::html($descrizione) ?></div>
                <?php elseif ($descrizione !== ''): ?>
                    <?php /* L'inizio con i tre puntini, come in «I miei corsi» (10/10). Qui
                             pero' lo studente non e' ancora iscritto e la pagina del corso
                             non la puo' aprire: la scheda e' l'unico posto dove legge la
                             descrizione prima di iscriversi. Per questo «Leggi tutto» la apre
                             dentro la scheda, e «Chiudi» la richiude (scelta di Alessandro).
                             E' un `details`: funziona senza JavaScript. Aperto, l'estratto
                             sparisce e «Chiudi» va sotto il testo. */ ?>
                    <div class="catalogo-descrizione">
                        <p class="card-meta catalogo-estratto"><?= htmlspecialchars(CourseModel::estratto($descrizione)) ?></p>
                        <details class="catalogo-leggi">
                            <summary><span class="catalogo-leggi-comando"><span class="catalogo-se-chiuso">Leggi tutto</span><span class="catalogo-se-aperto">Chiudi</span></span></summary>
                            <div class="card-meta testo-breve"><?= TestoBreve::html($descrizione) ?></div>
                        </details>
                    </div>
                <?php endif; ?>

                <?php /* Il blocco finale (06/11, variante B): numero di lezioni o
                         prezzo, e il pulsante, in fondo alla scheda. Cosi' nella
                         stessa riga si allineano anche con descrizioni ed
                         etichette di lunghezza diversa. */ ?>
                <div class="catalogo-fondo">
                <?php if ($pagato): ?>
                    <?php /* Corso a pagamento (06/11, variante B ridotta): il prezzo
                             in evidenza al posto delle lezioni, e il pulsante che
                             porta a Stripe con il numero dello studente e la sua
                             email. Niente etichetta, niente frase sotto. Il
                             pulsante dice «Iscriviti» come per i corsi gratuiti
                             (06/11, Alessandro): il prezzo sopra dice gia' che si
                             paga. */ ?>
                    <p class="catalogo-prezzo"><?= htmlspecialchars(CorsiAPagamento::prezzo((int) $course['price_cents'])) ?></p>
                    <div class="form-actions">
                        <a class="btn btn-primary"
                           href="<?= htmlspecialchars(CorsiAPagamento::linkPerStudente((string) $course['payment_link_url'], (int) Auth::id(), (string) Auth::email())) ?>">Iscriviti</a>
                    </div>
                <?php else: ?>

                <p class="card-meta catalogo-lezioni"><?= (int) $course['lesson_count'] ?> lezioni</p>

                <?php if ($pending): ?>
                    <p class="form-hint">Richiesta inviata: sarà valutata dall'Amministratore.</p>
                <?php else: ?>
                    <?php if ($rejected): ?>
                        <p class="form-hint">Una tua richiesta precedente non è stata accolta. Puoi riprovare.</p>
                    <?php endif; ?>

                    <form action="/catalogo/<?= $id ?>/iscrizione" method="post" class="form">
                        <?= Csrf::field() ?>

                        <?php if ($course['enrollment_mode'] === 'request'): ?>
                            <?php /* 1.000 caratteri con il contatore (10/10), come la
                                     presentazione del profilo: stesse classi e stesso
                                     script, una scheda per corso con il suo id. */ ?>
                            <div class="quiz-open-wrap campo-contato">
                                <div class="campo-contato-testa">
                                    <label for="message-<?= $id ?>">Due righe su di te (facoltativo)</label>
                                    <span class="quiz-open-count" id="message-<?= $id ?>-resta"
                                          data-max="<?= EnrollmentRequestModel::MAX_MESSAGE_CHARS ?>">
                                        <?= EnrollmentRequestModel::MAX_MESSAGE_CHARS ?> caratteri rimasti
                                    </span>
                                </div>
                                <textarea id="message-<?= $id ?>" name="message" rows="2"
                                          maxlength="<?= EnrollmentRequestModel::MAX_MESSAGE_CHARS ?>"
                                          class="quiz-open-answer" aria-describedby="message-<?= $id ?>-resta"
                                          placeholder="Perché ti interessa questo corso"></textarea>
                            </div>
                        <?php endif; ?>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <?= $course['enrollment_mode'] === 'open' ? 'Iscriviti' : 'Chiedi di iscriverti' ?>
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
                <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
    <script src="/assets/js/quiz-open-count.js"></script>
<?php endif; ?>
