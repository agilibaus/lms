<?php

declare(strict_types=1);

use App\Auth\CourseRights;
use App\Auth\Auth;
use App\Core\CourseAccess;
use App\Core\CourseCover;
use App\Core\Csrf;
use App\Core\Icona;
use App\Core\Obiettivo;
use App\Core\Percorso;
use App\Core\TestoBreve;

/** @var array $course */
/** @var array $modules */
/** @var array<int, array> $lessonsByModule */
/** @var int[] $completedLessonIds */
/** @var array<int, array|null> $quizByModule */
/** @var array<int, bool> $quizPassedByModule */
/** @var array<int, ?string> $questionariChiusi per lo studente: perche' il questionario e' ancora chiuso, o null (`Percorso::questionarioChiuso`) */
/** @var array<int, array{motivo: string, available_from: ?string}> $moduleLocks */
/** @var array|null $certificate */
/** @var string|null $obiettivoCorso «completo», «ridotto», o null senza obiettivo */
/** @var array{chiedi: ?array, mie: array<int, array>, esito: ?array}|null $treParole solo per lo studente */
/** @var bool $tuttiCompleti lo staff vede gli obiettivi sempre completi */
/** @var int|null $moduloInCorso */
/** @var array<int, string>|null $statiModuli lo stato di ogni modulo per lo studente; null per lo staff */
/** @var bool $tuttiModuli «Mostra tutti i moduli», per questa visita */
/** @var int|null $tuoGruppo il gruppo dello studente in questo corso, o null */

// Chi puo' modificare questo corso: l'admin sempre, il tutor solo se il corso e'
// assegnato a uno dei suoi gruppi. Gli altri tutor lo vedono in sola lettura.
$isStaff = CourseRights::canEdit((int) $course['id']);
?>
<div class="page-header">
    <a href="/" class="back-link"><span class="back-link-testo"><?= Auth::hasRole('admin', 'tutor', 'assistente') ? 'Tutti i corsi' : 'I miei corsi' ?></span></a>
    <h1><?= htmlspecialchars($course['title']) ?></h1>
    <?php /* Il gruppo e' parte della didattica (14/10, Alessandro): un
             collegamento solo, senza nome ne' tutor (il tutor sta nel
             benvenuto), e solo per lo studente che un gruppo nel corso ce
             l'ha. «Tuo» e non «del corso»: un corso puo' avere piu' gruppi. */ ?>
    <?php if (!empty($tuoGruppo)): ?>
        <p class="corso-tuo-gruppo"><a href="/gruppi/<?= (int) $tuoGruppo ?>?corso=<?= (int) $course['id'] ?>" class="corso-tuo-gruppo-link">Vai al tuo gruppo</a></p>
    <?php endif; ?>
</div>

<?php /* Le conferme e gli errori subito sotto il titolo: piu' in basso, con il
         benvenuto completo, sul telefono finirebbero fuori dallo schermo. */ ?>
<?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash_success']) ?></div>
    <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<?php /*
 * L'ORDINE DELLA TESTA DEL CORSO (07/10, scelto da Elena su due mockup):
 * titolo, copertina a fascia, descrizione, benvenuto del tutor, poi il resto.
 * Tutti i blocchi hanno la larghezza dei moduli: prima erano quattro
 * larghezze diverse allineate a sinistra (benvenuto 900 px, copertina 569,
 * descrizione 597, moduli 1016), e la pagina sembrava un collage.
 */ ?>
<?php $cover = CourseCover::url($course, false); ?>
<?php if ($cover !== null): ?>
    <div class="course-hero">
        <img src="<?= htmlspecialchars($cover) ?>" alt="<?= htmlspecialchars(CourseCover::altFor($course)) ?>">
    </div>
<?php endif; ?>

<?php if (!empty($course['description'])): ?>
    <?php /* Qualche tag HTML ammesso dall'11/10: `TestoBreve::html()` ripulisce
             e trasforma gli a capo in paragrafi e righe, come si vedevano. */ ?>
    <div class="course-description testo-breve"><?= TestoBreve::html((string) $course['description']) ?></div>
<?php endif; ?>

<?php if ($obiettivoCorso !== null): ?>
    <?php /* L'obiettivo del corso (11/10): dopo la descrizione, prima del
             benvenuto del tutor. Completo nelle prime tre visite, poi una riga
             che si riapre con «Mostra», come il benvenuto. */ ?>
    <?php $schedaObiettivo = '<section class="obiettivo-corso" aria-labelledby="obiettivo-corso-titolo">'
        . '<div class="obiettivo-corso-testa">' . Icona::svg('bullseye', 'icona-riquadro')
        . '<h2 id="obiettivo-corso-titolo">Obiettivo del corso</h2></div>'
        . '<div class="testo-breve">' . TestoBreve::html((string) $course['objective']) . '</div>'
        . '</section>'; ?>
    <?php if ($obiettivoCorso === 'completo'): ?>
        <?= $schedaObiettivo ?>
    <?php else: ?>
        <details class="obiettivo-ridotto">
            <summary>
                <?= Icona::svg('bullseye', 'icona-riquadro') ?>
                <span class="obiettivo-ridotto-nome">Obiettivo del corso</span>
                <span class="obiettivo-comando"><span class="obiettivo-se-chiuso">Mostra</span><span class="obiettivo-se-aperto">Nascondi</span></span>
            </summary>
            <?= $schedaObiettivo ?>
        </details>
    <?php endif; ?>
<?php endif; ?>

<?php if (!empty($welcome)): ?>
    <?php require __DIR__ . '/_welcome.php'; ?>
<?php endif; ?>

<?php if ($certificate !== null && $certificate['revoked_at'] === null): ?>
    <?php /* In un paragrafo, come ogni frase con un collegamento dentro (0117):
             cosi' «scarica il PDF» si legge come parte della frase, e' verde e
             sottolineato (0125), e le WCAG lo esentano dalla misura minima. */ ?>
    <div class="alert alert-success">
        <p>
            Hai completato il corso: certificato <code><?= htmlspecialchars((string) $certificate['certificate_code']) ?></code> —
            <a href="/certificates/<?= (int) $certificate['id'] ?>/download">scarica il PDF</a>.
        </p>
    </div>
<?php endif; ?>

<?php /* «Tre parole» alla fine di un modulo (21/10): solo per lo studente. */ ?>
<?php if (!empty($treParole)): ?>
    <?php require __DIR__ . '/_tre_parole.php'; ?>
<?php endif; ?>

<?php if ($isStaff): ?>
    <p><a href="/courses/<?= (int) $course['id'] ?>/modules/create" class="btn btn-primary btn-con-icona"><?= Icona::svg('plus-lg') ?>Nuovo modulo</a></p>
<?php endif; ?>

<?php if (empty($modules)): ?>
    <p class="empty-state">Nessun modulo ancora disponibile per questo corso.</p>
<?php else: ?>
    <?php /* La gerarchia dei moduli (11/10, `App\Core\Percorso`): per lo
             studente il modulo in corso e il prossimo sono aperti, gli altri
             sono righe che si aprono. Sopra, quanti moduli e quanti
             completati, e il comando che apre tutto per questa visita: un
             indirizzo, quindi anche senza JavaScript. Lo staff vede tutto
             aperto e niente di questo. */ ?>
    <?php if ($statiModuli !== null): ?>
        <?php
        $ciSonoRighe = count(array_filter($statiModuli, static fn (string $s): bool
            => $s === Percorso::COMPLETATO || $s === Percorso::DA_FARE)) > 0;
        ?>
        <?php /* Solo il comando (22/10, Alessandro, variante B): il conto
                 «8 moduli · 1 completato» e la riga del certificato, qui
                 sopra, erano due conti diversi prima ancora di cominciare.
                 L'avanzamento lo dicono i moduli (spunta, «In corso»); il
                 certificato sta in fondo, dopo l'ultimo modulo. */ ?>
        <div class="moduli-barra" id="moduli">
            <?php if ($tuttiModuli): ?>
                <a class="moduli-comando" href="/courses/<?= (int) $course['id'] ?>#moduli"><?= $moduloInCorso !== null ? 'Mostra solo il modulo in corso' : 'Richiudi i moduli' ?></a>
            <?php elseif ($ciSonoRighe): ?>
                <a class="moduli-comando" href="/courses/<?= (int) $course['id'] ?>?moduli=<?= Percorso::TUTTI ?>#moduli">Mostra tutti i moduli</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="module-list">
        <?php foreach ($modules as $moduleIndex => $module): ?>
            <?php
            $moduleId = (int) $module['id'];
            $quiz = $quizByModule[$moduleId] ?? null;
            $lock = $moduleLocks[$moduleId] ?? null;
            $isLocked = $lock !== null;
            // Un modulo chiuso da una data si annuncia con la data: lo
            // studente sa quando tornare. Uno chiuso da un quiz no, perche'
            // quando si apre dipende da lui.
            $lockedByDate = $isLocked && $lock['motivo'] === CourseAccess::MOTIVO_DATA
                && $lock['available_from'] !== null;
            $stato = $statiModuli[$moduleId] ?? null;
            // Aperto per lo staff, con «Mostra tutti i moduli», e per il
            // modulo in corso e il prossimo; gli altri sono una riga.
            $aperto = $stato === null || $tuttiModuli || $stato === Percorso::IN_CORSO || $stato === Percorso::PROSSIMO;
            $nLezioni = count($lessonsByModule[$moduleId] ?? []);
            ?>
            <?php if (!$aperto): ?>
                <?php
                if ($stato === Percorso::COMPLETATO) {
                    $statoRiga = 'completato · ' . Percorso::contenuto($nLezioni, $quiz !== null);
                } elseif ($lockedByDate) {
                    $statoRiga = 'si apre il ' . date('j/n/Y', strtotime((string) $lock['available_from']));
                } elseif ($isLocked) {
                    $statoRiga = 'si sblocca con il questionario del modulo precedente';
                } else {
                    $statoRiga = Percorso::contenuto($nLezioni, $quiz !== null);
                }
                ?>
                <details class="modulo-riga <?= $stato === Percorso::COMPLETATO ? 'modulo-riga-completato' : '' ?>" id="modulo-<?= $moduleId ?>">
                    <summary>
                        <?php if ($stato === Percorso::COMPLETATO): ?>
                            <span class="modulo-riga-spunta" aria-hidden="true"><?= Icona::svg('check-circle-fill') ?></span>
                        <?php endif; ?>
                        <h2 class="modulo-riga-titolo"><?= htmlspecialchars($module['title']) ?></h2>
                        <span class="modulo-riga-stato"><?= htmlspecialchars($statoRiga) ?></span>
                        <span class="obiettivo-comando"><span class="obiettivo-se-chiuso">Mostra</span><span class="obiettivo-se-aperto">Nascondi</span></span>
                    </summary>
                    <div class="modulo-riga-corpo <?= $isLocked ? 'module-locked' : '' ?>">
            <?php else: ?>
            <section class="module-card <?= $isLocked ? 'module-locked' : '' ?> <?= $stato === Percorso::IN_CORSO ? 'modulo-in-corso' : '' ?>" id="modulo-<?= $moduleId ?>">
                <div class="module-card-header">
                    <h2>
                        <?= htmlspecialchars($module['title']) ?>
                        <?php if ($stato === Percorso::IN_CORSO): ?>
                            <span class="etichetta-modulo">In corso</span>
                        <?php elseif ($stato === Percorso::PROSSIMO): ?>
                            <span class="etichetta-modulo etichetta-modulo-quieta">Prossimo</span>
                        <?php elseif ($stato === Percorso::COMPLETATO): ?>
                            <span class="etichetta-modulo etichetta-modulo-quieta etichetta-con-icona"><?= Icona::svg('check-circle-fill') ?> Completato</span>
                        <?php endif; ?>
                        <?php if ($lockedByDate): ?>
                            <span class="badge badge-muted">
                                dal <?= htmlspecialchars(date('j/n/Y', strtotime((string) $lock['available_from']))) ?>
                            </span>
                        <?php elseif ($isLocked): ?>
                            <span class="badge badge-danger">bloccato</span>
                        <?php elseif (!empty($module['quiz_required'])): ?>
                            <span class="badge">questionario obbligatorio</span>
                        <?php endif; ?>
                    </h2>
                    <?php if ($isStaff): ?>
                        <div class="module-card-actions">
                            <?php /* Le frecce stanno per prime: sono l'azione che si
                                     ripete, e cercarle ogni volta in fondo a un elenco
                                     di comandi diversi rallenta chi sta riordinando. */ ?>
                            <span class="order-actions">
                                <form action="/modules/<?= $moduleId ?>/move" method="post">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="direction" value="up">
                                    <button type="submit" class="icon-btn" title="Sposta il modulo su"
                                            aria-label="Sposta il modulo <?= htmlspecialchars($module['title']) ?> su"
                                            <?= $moduleIndex === 0 ? 'disabled' : '' ?>><?= Icona::svg('arrow-up') ?></button>
                                </form>
                                <form action="/modules/<?= $moduleId ?>/move" method="post">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="direction" value="down">
                                    <button type="submit" class="icon-btn" title="Sposta il modulo giù"
                                            aria-label="Sposta il modulo <?= htmlspecialchars($module['title']) ?> giù"
                                            <?= $moduleIndex === count($modules) - 1 ? 'disabled' : '' ?>><?= Icona::svg('arrow-down') ?></button>
                                </form>
                            </span>
                            <?php /* Le icone del vocabolario (19/10): il più per creare, la
                                     matita per modificare, il cestino per eliminare. Quello
                                     che prima diceva il «+» scritto, e ora dice l'icona, il
                                     lettore di schermo lo sente come parola: «Aggiungi
                                     Lezione», «Modifica Modulo». */ ?>
                            <a href="/modules/<?= $moduleId ?>/lessons/create" class="comando-con-icona"><?= Icona::svg('plus-lg') ?><span class="sr-only">Aggiungi </span>Lezione</a>
                            <?php if ($quiz === null): ?>
                                <a href="/modules/<?= $moduleId ?>/quiz/create" class="comando-con-icona"><?= Icona::svg('plus-lg') ?><span class="sr-only">Aggiungi </span>Questionario</a>
                            <?php else: ?>
                                <a href="/quizzes/<?= (int) $quiz['id'] ?>/edit" class="comando-con-icona"><?= Icona::svg('pencil') ?><span class="sr-only">Modifica </span>Questionario</a>
                            <?php endif; ?>
                            <a href="/modules/<?= $moduleId ?>/edit" class="comando-con-icona"><?= Icona::svg('pencil') ?><span class="sr-only">Modifica </span>Modulo</a>
                            <form action="/modules/<?= $moduleId ?>/delete" method="post"
                                  onsubmit="return confirm('Eliminare questo modulo, tutte le sue lezioni e le sue risorse della Bacheca?');">
                                <?= Csrf::field() ?>
                                <button type="submit" class="link-btn link-btn-danger comando-con-icona"><?= Icona::svg('trash3') ?>Elimina</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

                <?php /* L'obiettivo del modulo (11/10): completo nel modulo in
                         corso, ridotto a una riga negli altri; lo staff lo vede
                         sempre completo. Anche nei moduli chiusi. */ ?>
                <?php $obiettivoModulo = Obiettivo::htmlModulo($module['objective'] ?? null); ?>
                <?php if ($obiettivoModulo !== ''): ?>
                    <?php $bloccoObiettivo = '<div class="obiettivo-modulo testo-breve">' . $obiettivoModulo . '</div>'; ?>
                    <?php if ($tuttiCompleti || $moduleId === $moduloInCorso): ?>
                        <?= $bloccoObiettivo ?>
                    <?php else: ?>
                        <details class="obiettivo-modulo-ridotto">
                            <summary>
                                <?= Icona::svg('bullseye', 'icona-riquadro-piccola') ?>
                                <span>Obiettivo del modulo ·</span>
                                <span class="obiettivo-comando"><span class="obiettivo-se-chiuso">Mostra</span><span class="obiettivo-se-aperto">Nascondi</span></span>
                            </summary>
                            <?= $bloccoObiettivo ?>
                        </details>
                    <?php endif; ?>
                <?php endif; ?>

                <?php /* Le tre parole gia' scritte per questo modulo (21/10), da
                         rileggere: non si cambiano. */ ?>
                <?php $parole = $treParole['mie'][$moduleId] ?? null; ?>
                <?php if ($parole !== null): ?>
                    <p class="tre-parole-mie">
                        Le tue tre parole:
                        <span><?= htmlspecialchars($parole['word1']) ?> · <?= htmlspecialchars($parole['word2']) ?> · <?= htmlspecialchars($parole['word3']) ?></span>
                    </p>
                <?php endif; ?>

                <?php /* Dove riprendere, nel modulo in corso (13/10, Alessandro,
                         variante C del mockup): niente riquadro sopra
                         l'elenco. Sopra le lezioni il conto, piccolo e grigio;
                         nell'elenco la lezione da cui riprendere, su fondo
                         verde chiaro, con «Riprendi da qui» (o «Inizia da qui»
                         se nel modulo non ha fatto ancora niente). Con le
                         lezioni tutte fatte resta il questionario: la sua riga
                         ha lo stesso segno, con «Fallo ora». Il conto e' sulle
                         lezioni, non sul questionario. In un modulo chiuso
                         niente: c'e' la data. */ ?>
                <?php $ripresa = null; $restaQuestionario = false; ?>
                <?php /* Il questionario ancora chiuso (27/10): in grigio e
                         senza collegamento, senza frase. Lo dicono gia'
                         il posto in fondo al modulo, il conto delle lezioni
                         e «Inizia da qui»; quando si apre ha «Fallo ora». */ ?>
                <?php $questionarioChiuso = $questionariChiusi[$moduleId] ?? null; ?>
                <?php if ($stato === Percorso::IN_CORSO && !$isLocked): ?>
                    <?php $ripresa = Percorso::riprendi($lessonsByModule[$moduleId] ?? [], $completedLessonIds); ?>
                    <?php $restaQuestionario = $ripresa === null && $quiz !== null && $questionarioChiuso === null; ?>
                <?php endif; ?>

                <?php if ($lockedByDate): ?>
                    <p class="empty-state-small">
                        Disponibile dal
                        <?= htmlspecialchars(
                            date('j/n/Y \a\l\l\e H:i', strtotime((string) $lock['available_from']))
                        ) ?>.
                        Riceverai un’email quando si apre.
                    </p>
                <?php elseif ($isLocked): ?>
                    <p class="empty-state-small">
                        Supera il questionario del modulo precedente per sbloccare questo modulo.
                    </p>
                <?php else: ?>
                    <?php $lessons = $lessonsByModule[$moduleId] ?? []; ?>

                    <?php if (empty($lessons) && $quiz !== null): ?>
                        <?php /* La verifica finale (27/10): solo il questionario. */ ?>
                    <?php elseif (empty($lessons)): ?>
                        <p class="empty-state-small">Nessuna lezione in questo modulo.</p>
                    <?php else: ?>
                        <?php if ($ripresa !== null || $restaQuestionario): ?>
                            <p class="lezioni-fatte">
                                <?= $ripresa !== null ? $ripresa['fatte'] . ' di ' . $ripresa['totale'] : $nLezioni . ' di ' . $nLezioni ?>
                                lezioni fatte
                            </p>
                        <?php endif; ?>
                        <ul class="lesson-list">
                            <?php foreach ($lessons as $lessonIndex => $lesson): ?>
                                <?php $daQui = $ripresa !== null && (int) $ripresa['lezione']['id'] === (int) $lesson['id']; ?>
                                <li class="lesson-list-item<?= $daQui ? ' da-qui' : '' ?>">
                                    <?php if ($isStaff): ?>
                                        <span class="order-actions">
                                            <form action="/lessons/<?= (int) $lesson['id'] ?>/move" method="post">
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="direction" value="up">
                                                <button type="submit" class="icon-btn" title="Sposta la lezione su"
                                                        aria-label="Sposta la lezione <?= htmlspecialchars($lesson['title']) ?> su"
                                                        <?= $lessonIndex === 0 ? 'disabled' : '' ?>><?= Icona::svg('arrow-up') ?></button>
                                            </form>
                                            <form action="/lessons/<?= (int) $lesson['id'] ?>/move" method="post">
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="direction" value="down">
                                                <button type="submit" class="icon-btn" title="Sposta la lezione giù"
                                                        aria-label="Sposta la lezione <?= htmlspecialchars($lesson['title']) ?> giù"
                                                        <?= $lessonIndex === count($lessons) - 1 ? 'disabled' : '' ?>><?= Icona::svg('arrow-down') ?></button>
                                            </form>
                                        </span>
                                    <?php endif; ?>
                                    <?php
                                    /* L'icona del tipo di contenuto (18/10): la lezione
                                       completata ha la spunta piena, le altre il play se
                                       sono un video, il foglio se sono da leggere. Quello
                                       che dice solo l'icona, il lettore di schermo lo
                                       sente dopo il titolo. Prima c'era l'etichetta
                                       «video» sul bordo destro. */
                                    $fatta = in_array((int) $lesson['id'], $completedLessonIds, true);
                                    $video = $lesson['video_provider'] !== 'none';
                                    ?>
                                    <a href="/lessons/<?= (int) $lesson['id'] ?>" class="lezione-voce">
                                        <?= Icona::svg($fatta ? 'check-circle-fill' : ($video ? 'play-circle' : 'file-earmark-text'), $fatta ? 'icona-fatta' : '') ?>
                                        <span><?= htmlspecialchars($lesson['title']) ?><span class="sr-only"><?= $video ? ', video' : '' ?><?= $fatta ? ', completata' : '' ?></span></span>
                                    </a>
                                    <?php if ($daQui): ?>
                                        <a class="da-qui-comando" href="/lessons/<?= (int) $lesson['id'] ?>"><?= Icona::svg('play') ?><?= $ripresa['fatte'] > 0 ? 'Riprendi da qui' : 'Inizia da qui' ?></a>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($quiz !== null): ?>
                        <p class="module-quiz-row<?= $restaQuestionario ? ' da-qui' : '' ?>">
                            <?php if ($isStaff): ?>
                                <?php /* Lo scalino delle frecce di riordino, che
                                         il quiz non ha: ce n'e' uno solo per
                                         modulo e non si sposta. Serve a
                                         incolonnare il suo titolo con quelli
                                         delle lezioni, ed e' spaziatura, quindi
                                         un lettore di schermo non lo annuncia. */ ?>
                                <span class="ordine-vuoto" aria-hidden="true"></span>
                            <?php endif; ?>
                            <?php if ($questionarioChiuso !== null): ?>
                                <span class="quiz-link quiz-link-chiuso">
                                    <?= Icona::svg('clipboard-check') ?>Questionario: <?= htmlspecialchars((string) $quiz['title']) ?>
                                </span>
                            <?php else: ?>
                                <a href="/quizzes/<?= (int) $quiz['id'] ?>" class="quiz-link">
                                    <?= Icona::svg('clipboard-check') ?>Questionario: <?= htmlspecialchars((string) $quiz['title']) ?>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($quizPassedByModule[$moduleId])): ?>
                                <span class="badge badge-success">superato</span>
                            <?php endif; ?>
                            <?php if ($restaQuestionario): ?>
                                <a class="da-qui-comando" href="/quizzes/<?= (int) $quiz['id'] ?>"><?= Icona::svg('play') ?>Fallo ora</a>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            <?php if (!$aperto): ?>
                    </div>
                </details>
            <?php else: ?>
            </section>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/_questions.php'; ?>
