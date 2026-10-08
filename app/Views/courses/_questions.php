<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Models\QuestionModel;

/**
 * Domande e risposte in fondo al corso (07/10, chiesto da Elena).
 *
 * L'archivio: le domande pubblicate, divise per modulo nell'ordine del
 * corso, con «Il corso in generale» in fondo, e la ricerca. Ogni domanda e'
 * un `details`, si apre senza JavaScript. L'autore compare come ha scelto nel
 * profilo (il nome l'ha gia' deciso il controller, per chi guarda).
 *
 * Sotto, per lo studente: il campo per fare una domanda e «Le tue domande»
 * con lo stato. Niente risposte fra studenti: in mezzo c'e' sempre il tutor.
 *
 * @var array{archivio: list<array<string,mixed>>, cerca: string, mie: list<array<string,mixed>>,
 *            puoChiedere: bool, moduli: list<array<string,mixed>>} $domande
 * @var array<string, mixed> $course
 */

$courseId = (int) $course['id'];
$archivio = $domande['archivio'];
$cerca = $domande['cerca'];
// Il testo e il modulo di una domanda respinta, per non doverli rifare. Su
// un modulo nuovo il modulo e' null, e il menu parte dalla prima voce.
$respinta = isset($_SESSION['question_old']);
$vecchio = $_SESSION['question_old'] ?? ['text' => '', 'module' => null];
unset($_SESSION['question_old']);

// Raggruppate per modulo, nell'ordine in cui le restituisce il modello.
$perModulo = [];
foreach ($archivio as $q) {
    $chiave = $q['module_id'] === null ? 'generale' : 'm' . (int) $q['module_id'];
    $perModulo[$chiave]['titolo'] ??= $q['module_id'] === null ? 'Il corso in generale' : (string) $q['module_title'];
    $perModulo[$chiave]['voci'][] = $q;
}

$data = static fn (?string $d): string => $d === null ? '' : date('j/n/Y', strtotime($d));
$stati = [
    QuestionModel::PENDING => ['In attesa', 'qa-stato-attesa'],
    QuestionModel::PUBLISHED => ['Pubblicata', 'qa-stato-pubblicata'],
    QuestionModel::DISCARDED => ['Non pubblicata', 'qa-stato-scartata'],
];
?>
<?php
/*
 * Ripiegata in una riga, che si apre con «Mostra» e si chiude con
 * «Nascondi» (08/10, Elena: aperta occupava tanto spazio sotto il
 * programma). E' un `details`, come il benvenuto ridotto: senza JavaScript.
 *
 * Si apre da sola nei tre casi in cui chi arriva deve vedere qualcosa che
 * sta dentro: dopo una ricerca (i risultati), dopo aver inviato una domanda
 * («Le tue domande»), dopo un invio respinto (il testo da correggere).
 * Alla visita dopo riparte chiusa: la scelta non si ricorda.
 */
$aperta = $cerca !== '' || $respinta || !empty($_SESSION['domande_aperte']);
unset($_SESSION['domande_aperte']);
?>
<section class="qa" id="domande" aria-labelledby="qa-titolo">
    <details class="qa-apri"<?= $aperta ? ' open' : '' ?>>
    <summary class="qa-riga">
        <span class="qa-riga-testo">
            <h2 id="qa-titolo">Domande e risposte</h2>
            <span class="qa-conteggio"><?= (int) $domande['totale'] === 1 ? '1 domanda' : (int) $domande['totale'] . ' domande' ?></span>
        </span>
        <span class="qa-comando"><span class="qa-se-chiusa">Mostra</span><span class="qa-se-aperta">Nascondi</span></span>
    </summary>
    <div class="qa-contenuto">
    <p class="qa-intro">Le domande degli studenti di questo corso, con la risposta dei tutor.</p>

    <?php if ($archivio === []): ?>
        <p class="empty-state">
            <?= $cerca !== ''
                ? 'Nessuna domanda contiene «' . htmlspecialchars($cerca) . '».'
                : 'Ancora nessuna domanda pubblicata.' ?>
        </p>
    <?php endif; ?>

    <?php foreach ($perModulo as $gruppo): ?>
        <h3 class="qa-modulo"><?= htmlspecialchars($gruppo['titolo']) ?></h3>
        <ul class="qa-elenco" role="list">
            <?php foreach ($gruppo['voci'] as $q): ?>
                <li>
                    <details class="qa-voce"<?= $cerca !== '' ? ' open' : '' ?>>
                        <summary><?= htmlspecialchars((string) $q['question']) ?></summary>
                        <div class="qa-corpo">
                            <p class="qa-chi">Chiesto da <?= htmlspecialchars((string) $q['autore']) ?> · <?= $data($q['created_at']) ?></p>
                            <div class="qa-risposta">
                                <p><?= nl2br(htmlspecialchars((string) $q['answer']), false) ?></p>
                                <?php if (!empty($q['answered_by_name'])): ?>
                                    <p class="qa-chi">Risposta di <?= htmlspecialchars((string) $q['answered_by_name']) ?></p>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($q['gestibile'])): ?>
                                <?php /* Per chi la puo' gestire (08/10): porta alla domanda,
                                         gia' aperta, nella pagina «Domande». Un `div` e non
                                         un `p`: e' un comando, non un collegamento dentro una
                                         frase, e in un paragrafo prenderebbe il verde e la
                                         sottolineatura delle frasi (0125). */ ?>
                                <div class="qa-modifica">
                                    <a href="/domande?apri=<?= (int) $q['id'] ?>#pubblicata-<?= (int) $q['id'] ?>">Modifica</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </details>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>

    <?php /* In fondo all'archivio (Elena, 08/10): «non hai trovato quello
             che cercavi?». Dopo una ricerca i risultati stanno sopra, e
             accanto c'e' «Mostra tutte». */ ?>
        <?php /* La ricerca e' un GET sulla pagina del corso e torna all'archivio
                 (#domande): funziona senza JavaScript, e l'indirizzo si puo'
                 condividere. */ ?>
        <form method="get" action="/courses/<?= $courseId ?>#domande" class="form-inline qa-cerca" role="search">
            <label for="qa-cerca" class="sr-only">Cerca nelle domande e nelle risposte</label>
            <input type="search" id="qa-cerca" name="cerca" maxlength="100" placeholder="Cerca nelle domande e nelle risposte"
                   value="<?= htmlspecialchars($cerca) ?>">
            <button type="submit" class="btn btn-secondary">Cerca</button>
            <?php if ($cerca !== ''): ?>
                <a href="/courses/<?= $courseId ?>#domande" class="link-btn">Mostra tutte</a>
            <?php endif; ?>
        </form>
    </div>
    </details>

    <?php /* Il secondo riquadro, staccato: chiedere, e le proprie domande
             (Elena, 08/10). Fuori dal `details` perche' e' un riquadro suo;
             lo stile lo nasconde quando la sezione e' chiusa. */ ?>
    <?php if ($domande['puoChiedere']): ?>
        <div class="card qa-chiedi">
            <h2>Fai una domanda al tutor</h2>
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

                <label for="qa-domanda">La tua domanda</label>
                <textarea id="qa-domanda" name="question" rows="3" required
                          maxlength="<?= QuestionModel::MAX_QUESTION_CHARS ?>"><?= htmlspecialchars((string) $vecchio['text']) ?></textarea>
                <p class="form-hint">
                    La legge il tuo tutor. Se la pubblica, compare qui sopra con la risposta, con il tuo nome come
                    hai scelto nel profilo. Prima di pubblicarla può correggerla.
                </p>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Invia la domanda</button>
                </div>
            </form>

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
    <?php endif; ?>
</section>
