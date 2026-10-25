<?php

declare(strict_types=1);

/**
 * L'archivio delle domande e risposte di un corso (07/10; dal 09/10 nella
 * pagina «Domande e risposte», non piu' in fondo al corso).
 *
 * Le domande pubblicate, divise per modulo nell'ordine del corso, con «Il
 * corso in generale» in fondo; ogni domanda e' un `details`, si apre senza
 * JavaScript. L'autore compare come ha scelto nel profilo a chi e' della sua
 * classe, e come «uno studente del corso» agli altri (25/10); lo decide il
 * controller, per chi guarda, che toglie anche le domande dei moduli chiusi.
 *
 * LA RICERCA STA IN CIMA (21/10, Alessandro). Prima era in fondo (Elena,
 * 08/10: «non hai trovato quello che cercavi?»), ma con le domande di otto
 * moduli finiva sotto pagine di elenco, e chi arrivava per cercare non
 * sapeva che c'era. Il «non hai trovato?» resta in fondo, come frase con
 * due rimandi: alla ricerca, e a «Fai una domanda all'esperto» nel corso.
 * Con meno di cinque domande (`$cercaDa`) il campo non c'e': si leggono prima di aver
 * finito di scrivere. Se pero' si sta cercando, resta, con «Mostra tutte».
 *
 * @var list<array<string, mixed>> $archivio
 * @var string $cerca
 * @var string $indirizzo L'indirizzo della pagina, per la ricerca e «Mostra tutte»
 * @var array<string, string> $campiNascosti Campi da tenere nella ricerca (il corso)
 * @var int $totale Le pubblicate del corso, senza la ricerca
 * @var string|null $chiedi Dove si fa una domanda (null: chi guarda non ne fa)
 */

$cercaDa = 5;
$conRicerca = $totale >= $cercaDa || $cerca !== '';

$perModulo = [];
foreach ($archivio as $q) {
    $chiave = $q['module_id'] === null ? 'generale' : 'm' . (int) $q['module_id'];
    $perModulo[$chiave]['titolo'] ??= $q['module_id'] === null ? 'Il corso in generale' : (string) $q['module_title'];
    $perModulo[$chiave]['voci'][] = $q;
}
$data = static fn (?string $d): string => $d === null ? '' : date('j/n/Y', strtotime($d));
$tutte = $indirizzo . ($campiNascosti !== [] ? '?' . http_build_query($campiNascosti) : '');
?>
<p class="qa-intro">Le domande degli studenti di questo corso, con la risposta dell'esperto.</p>

<?php if ($conRicerca): ?>
<?php /* La ricerca e' un GET sulla pagina, senza JavaScript, e l'indirizzo si
         puo' condividere; tiene il corso scelto. */ ?>
<form method="get" action="<?= htmlspecialchars($indirizzo) ?>" class="form-inline qa-cerca" role="search">
    <?php foreach ($campiNascosti as $nome => $valore): ?>
        <input type="hidden" name="<?= htmlspecialchars($nome) ?>" value="<?= htmlspecialchars($valore) ?>">
    <?php endforeach; ?>
    <label for="qa-cerca" class="sr-only">Cerca nelle domande e nelle risposte</label>
    <input type="search" id="qa-cerca" name="cerca" maxlength="100" placeholder="Cerca nelle domande e nelle risposte"
           value="<?= htmlspecialchars($cerca) ?>">
    <button type="submit" class="btn btn-secondary">Cerca</button>
    <?php if ($cerca !== ''): ?>
        <a href="<?= htmlspecialchars($tutte) ?>" class="link-btn">Mostra tutte</a>
    <?php endif; ?>
</form>
<?php endif; ?>

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
                    </div>
                </details>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endforeach; ?>

<?php /* In fondo, per chi ha letto tutto senza trovare (l'idea di Elena,
         08/10): non un secondo campo, ma i due posti dove andare. */ ?>
<?php if ($archivio !== [] && ($conRicerca || $chiedi !== null)): ?>
    <p class="qa-non-trovato">
        Non hai trovato quello che cercavi?
        <?php if ($conRicerca): ?>
            <a href="#qa-cerca">Cerca</a><?= $chiedi !== null ? ' o' : '' ?>
        <?php endif; ?>
        <?php if ($chiedi !== null): ?>
            <a href="<?= htmlspecialchars($chiedi) ?>"><?= $conRicerca ? 'fai' : 'Fai' ?> una domanda all’esperto</a>.
        <?php endif; ?>
    </p>
<?php endif; ?>
