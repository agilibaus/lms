<?php

declare(strict_types=1);

use App\Controllers\GroupPageController;
use App\Core\CampoContato;
use App\Core\CourseCover;
use App\Core\Csrf;
use App\Core\GiroDelCerchio;
use App\Core\GroupCircle;
use App\Models\GroupQuestionModel;

/** @var array $group */
/** @var array|null $tutor */
/** @var array $people */
/** @var int $partecipanti */
/** @var bool $modera */
/** @var bool $partecipa */
/** @var array|null $domanda */
/** @var list<array<string, mixed>> $risposte */
/** @var int $contate */
/** @var int $archivio */
/** @var array|null $incontro */
/** @var list<array<string, mixed>> $ciSaranno */
/** @var bool $ciSaro */
/** @var int $chiGuarda */
/** @var array|null $daRiprendere */

$quanti = count($people);
$posizioni = GroupCircle::posizioni($quanti);

/*
 * La foto, o le iniziali quando manca. `alt` vuoto perche' il nome e' scritto
 * subito accanto: ripeterlo farebbe dire ogni nome due volte a un lettore di
 * schermo.
 */
$foto = static function (array $persona): string {
    if (!empty($persona['avatar_path'])) {
        return '<img class="avatar persona-foto" src="/utenti/' . (int) $persona['id'] . '/immagine" alt="" loading="lazy">';
    }

    return '<span class="avatar avatar-placeholder persona-foto" aria-hidden="true">'
        . htmlspecialchars(CourseCover::initials((string) $persona['full_name']))
        . '</span>';
};

/*
 * LA PRESENTAZIONE (06/10, chiesto da Elena). Chi l'ha scritta diventa un
 * pulsante — foto e nome insieme — che apre una scheda sopra la pagina con
 * l'attributo `popovertarget`: niente JavaScript, e il browser gestisce da
 * se' Esc, il clic fuori e il ritorno del fuoco. Il cerchio non si sposta.
 *
 * Le schede stanno **dopo** l'elenco, non dentro le voci: un browser che
 * non conosce i popover le mostra come un elenco di presentazioni sotto i
 * partecipanti, ed e' il ripiego. Dentro le voci rovinerebbero il cerchio.
 *
 * Chi ne ha una lo dice un segno a forma di fumetto sull'angolo della foto
 * — la forma, non un colore (§4) — e, per un lettore di schermo, il testo
 * nascosto «leggi la presentazione» nel nome del pulsante.
 */
$haPresentazione = static fn (array $persona): bool => trim((string) ($persona['bio'] ?? '')) !== '';

$voce = static function (array $persona, string $nome) use ($foto, $haPresentazione): string {
    if (!$haPresentazione($persona)) {
        return $foto($persona) . $nome;
    }

    return '<button type="button" class="persona-apri" popovertarget="presentazione-' . (int) $persona['id'] . '">'
        . '<span class="persona-foto-cornice">' . $foto($persona)
        . '<span class="persona-fumetto" aria-hidden="true"></span></span>'
        . $nome
        . '</button>';
};

$conPresentazione = array_values(array_filter(
    array_merge($tutor !== null ? [$tutor] : [], $people),
    $haPresentazione
));
?>
<div class="page-header">
    <a href="/profilo" class="back-link"><span class="back-link-testo">Profilo</span></a>
    <h1><?= htmlspecialchars(GroupPageController::titolo((string) $group['name'])) ?></h1>
    <p class="page-subtitle">
        <?= $quanti === 1 ? '1 partecipante' : $quanti . ' partecipanti' ?>
        <?php if ($tutor !== null): ?>
            · tutor: <?= htmlspecialchars((string) $tutor['full_name']) ?>
        <?php endif; ?>
    </p>
</div>

<?php require __DIR__ . '/../admin/_flash.php'; ?>

<?php if ($incontro !== null): ?>
    <?php /* Il prossimo incontro del gruppo (12/10): in cima, perche' e'
             l'unica cosa della pagina con una scadenza. «Ci sarò» lo dicono
             i partecipanti; chi modera vede chi ci sara'. */
    $quantiCi = count($ciSaranno); ?>
    <section class="card gruppo-incontro" aria-labelledby="gruppo-incontro-titolo">
        <div class="gruppo-incontro-testo">
            <h2 class="gruppo-riquadro-titolo" id="gruppo-incontro-titolo">Prossimo incontro del gruppo</h2>
            <h3 class="gruppo-incontro-nome"><a href="/live/<?= (int) $incontro['id'] ?>"><?= htmlspecialchars((string) $incontro['title']) ?></a></h3>
            <p class="gruppo-incontro-quando"><?= htmlspecialchars(GiroDelCerchio::quando((string) $incontro['starts_at'], (string) $incontro['ends_at'])) ?></p>
        </div>
        <div class="gruppo-incontro-chi">
            <?php if ($quantiCi > 0): ?>
                <span class="gruppo-volti" aria-hidden="true">
                    <?php foreach (array_slice($ciSaranno, 0, 6) as $p): ?>
                        <?php if (!empty($p['avatar_path'])): ?>
                            <img class="gruppo-volto" src="/utenti/<?= (int) $p['id'] ?>/immagine" alt="" loading="lazy">
                        <?php else: ?>
                            <span class="gruppo-volto"><?= htmlspecialchars(CourseCover::initials((string) $p['full_name'])) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </span>
                <span class="gruppo-ci-saranno">
                    <?= $quantiCi === 1 ? '1 ci sarà' : $quantiCi . ' ci saranno' ?><span class="sr-only">: <?= htmlspecialchars(implode(', ', array_map(static fn (array $p): string => (string) $p['full_name'], $ciSaranno))) ?></span>
                </span>
            <?php else: ?>
                <span class="gruppo-ci-saranno">Ancora nessuno ha detto «Ci sarò»</span>
            <?php endif; ?>
            <?php if ($partecipa): ?>
                <form action="/gruppi/<?= (int) $group['id'] ?>/ci-saro" method="post" class="gruppo-ci-saro">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="session_id" value="<?= (int) $incontro['id'] ?>">
                    <?php if ($ciSaro): ?>
                        <input type="hidden" name="ci" value="0">
                        <span class="gruppo-ci-sarai">Ci sarai</span>
                        <button type="submit" class="link-btn">Non posso più</button>
                    <?php else: ?>
                        <input type="hidden" name="ci" value="1">
                        <button type="submit" class="btn btn-primary">Ci sarò</button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    </section>
<?php else: ?>
    <?php /* Nessun incontro del gruppo in programma (17/10, Alessandro,
             variante A del mockup): il riquadro resta, con una frase, cosi'
             la pagina non si svuota il giorno dopo un incontro. */ ?>
    <section class="card gruppo-incontro" aria-labelledby="gruppo-incontro-titolo">
        <div class="gruppo-incontro-testo">
            <h2 class="gruppo-riquadro-titolo" id="gruppo-incontro-titolo">Prossimo incontro del gruppo</h2>
            <p class="gruppo-incontro-quando">Nessun incontro in programma, per ora.</p>
        </div>
    </section>
<?php endif; ?>

<?php if ($quanti === 0 && $tutor === null): ?>
    <p class="empty-state">Questo gruppo non ha ancora partecipanti.</p>
<?php else: ?>
    <?php /*
     * Un elenco solo, nello stesso ordine per tutti: il tutor prima, poi i
     * partecipanti in ordine di nome. E' la griglia del telefono; su un
     * computer, con spazio a sufficienza e non piu' di
     * GroupCircle::MASSIMO partecipanti, il CSS dispone le stesse voci in
     * cerchio usando le coordinate scritte qui sotto. Senza query di
     * contenitore resta la griglia, che e' il ripiego.
     *
     * `role="list"` perche' togliere i pallini a un elenco ne fa perdere la
     * semantica in Safari.
     *
     * In un riquadro bianco come l'incontro e il giro del cerchio (13/10,
     * Alessandro, variante A del mockup): senza titolo, perche' «Gruppo
     * Giallo» in cima alla pagina dice gia' chi sono.
     */ ?>
    <div class="card gruppo-persone">
        <ul class="gruppo-cerchio<?= GroupCircle::usaCerchio($quanti) ? ' usa-cerchio' : '' ?>" role="list">
            <?php if ($tutor !== null): ?>
                <li class="persona persona-tutor">
                    <?= $voce($tutor, '<span class="persona-nome">'
                        . htmlspecialchars((string) $tutor['full_name'])
                        . ($haPresentazione($tutor) ? '<span class="sr-only">, leggi la presentazione</span>' : '')
                        . '<span class="persona-ruolo">tutor</span></span>') ?>
                </li>
            <?php endif; ?>

            <?php foreach ($people as $i => $persona): ?>
                <?php $pos = $posizioni[$i]; ?>
                <li class="persona persona-lato-<?= $pos['lato'] ?>"
                    style="--x: <?= sprintf('%.2F', $pos['x']) ?>; --y: <?= sprintf('%.2F', $pos['y']) ?>;">
                    <?= $voce($persona, '<span class="persona-nome">'
                        . htmlspecialchars((string) $persona['full_name'])
                        . ($haPresentazione($persona) ? '<span class="sr-only">, leggi la presentazione</span>' : '')
                        // Solo sotto il proprio nome (08/10): come lo vedono gli
                        // altri. Testo e non un collegamento: il nome puo' stare
                        // dentro il pulsante della presentazione, e un
                        // collegamento dentro un pulsante non si raggiunge.
                        . (!empty($persona['come_ti_vedono'])
                            ? '<span class="persona-come-ti-vedono">Gli altri ti vedono come «'
                                . htmlspecialchars((string) $persona['come_ti_vedono']) . '»</span>'
                            : '')
                        . '</span>') ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <?php /*
     * IL GIRO DEL CERCHIO (12/10, Alessandro, variante B del mockup): sotto il
     * cerchio, che resta com'e', la domanda aperta e le risposte intere, una
     * sotto l'altra, la propria per prima. Pone la domanda il tutor del
     * gruppo o l'admin; una domanda nuova manda la precedente fra le domande
     * passate. Regole in `App\Core\GiroDelCerchio`.
     */
    $gid = (int) $group['id'];
    $mia = null;
    foreach ($risposte as $r) {
        if ($r['mia']) {
            $mia = $r;
        }
    } ?>
    <?php if ($domanda !== null || $modera): ?>
        <section class="card giro" id="giro" aria-labelledby="giro-titolo">
            <?php if ($domanda !== null): ?>
                <div class="giro-testa">
                    <?php if (!empty($domanda['author_avatar'])): ?>
                        <img class="avatar giro-foto" src="/utenti/<?= (int) $domanda['author_id'] ?>/immagine" alt="" loading="lazy">
                    <?php else: ?>
                        <span class="avatar avatar-placeholder giro-foto" aria-hidden="true"><?= htmlspecialchars(CourseCover::initials((string) ($domanda['author_name'] ?? '?'))) ?></span>
                    <?php endif; ?>
                    <div>
                        <h2 class="gruppo-riquadro-titolo" id="giro-titolo">Il giro del cerchio</h2>
                        <p class="giro-chi"><?= htmlspecialchars(GiroDelCerchio::chiChiede($domanda['author_first_name'] ?? null)) ?></p>
                    </div>
                </div>
                <h3 class="giro-domanda"><?= nl2br(htmlspecialchars((string) $domanda['question']), false) ?></h3>
                <p class="giro-conto"><?= htmlspecialchars(GiroDelCerchio::conto($contate, $partecipanti)) ?></p>

                <?php if ($partecipa && ($mia === null || $mia['hidden_at'] === null)): ?>
                    <?php $valore = $daRiprendere['answer'] ?? ($mia['answer'] ?? ''); ?>
                    <?php $modulo = '<form action="/gruppi/' . $gid . '/risposta" method="post" class="giro-modulo">'
                        . Csrf::field()
                        . '<input type="hidden" name="question_id" value="' . (int) $domanda['id'] . '">'
                        . CampoContato::html('giro-risposta', 'La tua risposta', 'answer', GroupQuestionModel::MAX_RISPOSTA, (string) $valore, 3)
                        . '<p class="form-hint">La leggono i partecipanti e il tutor del gruppo.'
                        . ($mia !== null ? ' Svuota il campo e salva per togliere la risposta.' : '') . '</p>'
                        . '<div class="form-actions"><button type="submit" class="btn btn-primary">'
                        . ($mia !== null ? 'Salva' : 'Condividi') . '</button>'
                        // «Annulla» accanto a «Salva» e a «Condividi» (14/10):
                        // rimette la risposta salvata (o svuota il campo) e
                        // chiude il pannello «Modifica la tua risposta».
                        . '<button type="reset" class="btn btn-secondary" data-annulla-chiude>Annulla</button>'
                        . '</div></form>'; ?>
                    <?php if ($mia === null || $daRiprendere !== null): ?>
                        <?= $modulo ?>
                    <?php else: ?>
                        <details class="giro-cambia">
                            <summary class="giro-cambia-comando">Modifica la tua risposta</summary>
                            <?= $modulo ?>
                        </details>
                    <?php endif; ?>
                <?php endif; ?>

                <?php require __DIR__ . '/_risposte.php'; ?>
            <?php endif; ?>

            <?php if ($modera): ?>
                <?php if ($domanda === null): ?>
                    <h2 class="gruppo-riquadro-titolo" id="giro-titolo">Il giro del cerchio</h2>
                    <p class="giro-spiega">Fai una domanda al gruppo: i partecipanti rispondono in una o due righe, e le risposte si leggono qui. Resta aperta finché non ne fai una nuova.</p>
                <?php endif; ?>
                <?php $nuova = '<form action="/gruppi/' . $gid . '/domanda" method="post" class="giro-modulo">'
                    . Csrf::field()
                    . CampoContato::html('giro-domanda', 'La domanda al gruppo', 'question', GroupQuestionModel::MAX_DOMANDA, '', 3)
                    . ($domanda !== null ? '<p class="form-hint">La domanda di adesso, con le sue risposte, passa fra le domande passate.</p>' : '')
                    . '<div class="form-actions"><button type="submit" class="btn ' . ($domanda !== null ? 'btn-secondary' : 'btn-primary') . '">Poni la domanda</button>'
                    // «Annulla» (14/10): svuota il campo e chiude «Fai una nuova domanda».
                    . '<button type="reset" class="btn btn-secondary" data-annulla-chiude>Annulla</button></div></form>'; ?>
                <?php if ($domanda === null): ?>
                    <?= $nuova ?>
                <?php else: ?>
                    <details class="giro-nuova">
                        <summary class="giro-cambia-comando">Fai una nuova domanda</summary>
                        <?= $nuova ?>
                    </details>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($archivio > 0): ?>
                <p class="giro-passate"><a class="giro-passate-comando" href="/gruppi/<?= $gid ?>/domande-passate">Domande passate (<?= $archivio ?>)</a></p>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <?php /* Nessuna domanda, per chi non la puo' fare (17/10, variante A):
                 il riquadro resta, con una frase. Le domande passate non ci
                 sono: la domanda aperta e' l'ultima fatta, e senza di lei non
                 ce n'e' nessuna in archivio. */ ?>
        <section class="card giro" id="giro" aria-labelledby="giro-titolo">
            <h2 class="gruppo-riquadro-titolo" id="giro-titolo">Il giro del cerchio</h2>
            <p class="giro-spiega">Il tutor non ha ancora fatto una domanda al gruppo. Quando la farà, la trovi qui.</p>
        </section>
    <?php endif; ?>

    <?php if ($conPresentazione !== []): ?>
        <div class="presentazioni">
            <?php foreach ($conPresentazione as $persona): ?>
                <?php $pid = 'presentazione-' . (int) $persona['id']; ?>
                <section class="presentazione" id="<?= $pid ?>" popover aria-labelledby="<?= $pid ?>-nome">
                    <div class="presentazione-testa">
                        <?= $foto($persona) ?>
                        <h2 id="<?= $pid ?>-nome"><?= htmlspecialchars((string) $persona['full_name']) ?></h2>
                        <?php /* Il comando per chiudere c'e' solo dove il
                                 popover funziona: nel ripiego la scheda e'
                                 una sezione della pagina, e non c'e' niente
                                 da chiudere. */ ?>
                        <button type="button" class="presentazione-chiudi"
                                popovertarget="<?= $pid ?>" popovertargetaction="hide">
                            <span aria-hidden="true">&times;</span>
                            <span class="sr-only">Chiudi</span>
                        </button>
                    </div>
                    <p class="presentazione-testo"><?= nl2br(htmlspecialchars(trim((string) $persona['bio'])), false) ?></p>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
