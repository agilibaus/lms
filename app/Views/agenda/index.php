<?php

declare(strict_types=1);

use App\Core\Agenda;
use App\Core\Csrf;
use App\Core\Url;

/**
 * L'agenda: elenco e mese.
 *
 * Due viste sulla stessa pagina e lo stesso indirizzo (`?vista=mese`), non
 * due pagine: si passa dall'una all'altra senza perdere il posto, e il
 * collegamento che si manda a qualcuno porta la vista che si stava
 * guardando.
 *
 * @var string $vista
 * @var DateTimeImmutable $mese
 * @var list<array<string, mixed>> $eventi
 * @var array<string, list<array<string, mixed>>> $gruppi
 * @var list<list<array<string, mixed>>> $settimane
 * @var array{token: string, creato: ?string, usato: ?string}|null $calendario
 */

$esc = static fn (?string $v): string => htmlspecialchars((string) $v);
$adesso = new DateTimeImmutable('now');

/**
 * Una voce dell'elenco.
 *
 * Il tipo si vede da due cose insieme — un'etichetta scritta e un colore —
 * perche' il colore da solo non lo distingue chi non lo distingue (1.4.1
 * delle WCAG: «l'uso del colore»).
 */
$voce = static function (array $e) use ($esc, $adesso): string {
    $incontro = $e['tipo'] === Agenda::INCONTRO;
    $fine = $e['fine'] ?? $e['inizio'];
    $inCorso = $incontro && $e['inizio'] <= $adesso && $adesso <= $fine;

    // «Entra» compare solo quando serve davvero: da un quarto d'ora prima
    // fino alla fine. Un pulsante che porta a una riunione che comincia
    // fra tre settimane e' un invito a sbagliare.
    $apribile = $incontro
        && $adesso >= $e['inizio']->modify('-15 minutes')
        && $adesso <= $fine;

    $ora = $e['inizio']->format('H:i');
    $ora .= $incontro && $e['fine'] !== null ? '–' . $e['fine']->format('H:i') : '';

    $html = '<li class="agenda-voce agenda-' . $esc($e['tipo']) . ($inCorso ? ' agenda-in-corso' : '') . '">'
        . '<span class="agenda-quando"><span class="agenda-giorno">'
        . $esc($e['inizio']->format('d/m')) . '</span> <span class="agenda-ora">' . $esc($ora) . '</span></span>'
        . '<span class="agenda-cosa">'
        . '<a href="' . $esc((string) $e['url']) . '">' . $esc((string) $e['titolo']) . '</a>'
        . '<span class="agenda-tipo">' . $esc(Agenda::etichettaTipo((string) $e['tipo'])) . '</span>'
        . ($e['contesto'] === '' ? '' : '<span class="agenda-contesto">' . $esc((string) $e['contesto']) . '</span>')
        . '</span>'
        . '<span class="agenda-azioni">';

    if ($inCorso) {
        $html .= '<span class="badge badge-success">in corso</span>';
    }

    if ($apribile) {
        $html .= '<a class="btn btn-primary" href="/live/' . (int) $e['id'] . '/join">Entra</a>';
    }

    // Non per un incontro gia' finito: mettere in agenda un appuntamento
    // passato non serve a niente. La condizione e' la stessa con cui
    // `Agenda::raggruppa()` manda una voce nello Storico — la fine se c'e',
    // altrimenti l'inizio — cosi' il collegamento sparisce esattamente
    // quando la voce scende li' sotto.
    if ($incontro && $adesso <= $fine) {
        // «Aggiungi al calendario» e non «Al calendario»: in una riga di
        // comandi un'etichetta senza verbo non dice cosa succede a
        // cliccarla. Non «in agenda», che qui e' il nome della pagina:
        // il calendario di destinazione e' quello di chi legge.
        $html .= '<a href="/agenda/evento/' . (int) $e['id'] . '.ics">Aggiungi al calendario</a>';
    }

    return $html . '</span></li>';
};

/** L'indirizzo di questa pagina con una vista o un mese diversi. */
$link = static function (string $v, ?DateTimeImmutable $m = null) use ($mese): string {
    $q = ['vista' => $v];

    if ($v === 'mese') {
        $q['mese'] = ($m ?? $mese)->format('Y-m');
    }

    return '/agenda?' . http_build_query($q);
};

$titoliGruppi = [
    'oggi' => 'Oggi',
    'settimana' => 'Nei prossimi sette giorni',
    'prossimi' => 'Pianificato',
];
?>
<div class="page-header">
    <h1>Agenda</h1>
    <p class="page-subtitle">
        Gli incontri dal vivo dei tuoi corsi e dei tuoi gruppi, e le date in cui si aprono
        i moduli a rilascio programmato.
    </p>
</div>

<?php require __DIR__ . '/../admin/_flash.php'; ?>

<?php /* Le due viste sono un gruppo di collegamenti, non una tendina:
         sono due, si vedono tutte e due, e quella attiva si riconosce
         anche da `aria-current` e non solo dal colore. */ ?>
<nav class="agenda-viste" aria-label="Come vedere l'agenda">
    <a href="<?= $esc($link('elenco')) ?>" class="<?= $vista === 'elenco' ? 'attiva' : '' ?>"
       <?= $vista === 'elenco' ? 'aria-current="page"' : '' ?>>Elenco</a>
    <a href="<?= $esc($link('mese')) ?>" class="<?= $vista === 'mese' ? 'attiva' : '' ?>"
       <?= $vista === 'mese' ? 'aria-current="page"' : '' ?>>Mese</a>
</nav>

<?php if ($eventi === []): ?>
    <p class="empty-state">
        Non c'è ancora niente in agenda. Qui compariranno gli incontri dal vivo dei tuoi
        corsi e dei tuoi gruppi, e i moduli che si aprono a una data.
    </p>
<?php elseif ($vista === 'elenco'): ?>
    <?php foreach ($titoliGruppi as $chiave => $titolo): ?>
        <?php if ($gruppi[$chiave] !== []): ?>
            <section class="agenda-gruppo">
                <h2><?= $esc($titolo) ?></h2>
                <ul class="agenda-elenco">
                    <?php foreach ($gruppi[$chiave] as $e): ?>
                        <?= $voce($e) ?>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($gruppi['oggi'] === [] && $gruppi['settimana'] === [] && $gruppi['prossimi'] === []): ?>
        <p class="empty-state">Niente in programma. Qui sotto puoi vedere lo storico.</p>
    <?php endif; ?>

    <?php if ($gruppi['passati'] !== []): ?>
        <?php /* Il passato c'e' ma sta chiuso: serve per ricordare, non per
                 programmare, e aperto spingerebbe in fondo le cose che
                 contano. */ ?>
        <details class="agenda-passati">
            <?php /* Il titolo sta dentro al `summary` e non accanto: lo
                     Storico e' il quarto gruppo dell'elenco, quindi un `h2`
                     come gli altri tre — stessa misura senza doverla
                     ridichiarare, e un lettore di schermo annuncia quattro
                     sezioni invece di tre piu' un bottone. Un `h2` come
                     primo figlio di `summary` e' HTML valido e non salta
                     nessun livello: sopra c'e' l'h1 della pagina. */ ?>
            <summary><h2>Storico (<?= count($gruppi['passati']) ?>)</h2></summary>
            <ul class="agenda-elenco">
                <?php foreach ($gruppi['passati'] as $e): ?>
                    <?= $voce($e) ?>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
<?php else: ?>
    <?php
    $precedente = $mese->modify('-1 month');
    $successivo = $mese->modify('+1 month');
    ?>
    <nav class="agenda-mesi" aria-label="Cambia mese">
        <a class="btn btn-secondary" href="<?= $esc($link('mese', $precedente)) ?>" rel="prev">
            &larr; <?= $esc(Agenda::nomeMese($precedente)) ?>
        </a>
        <h2><?= $esc(Agenda::nomeMese($mese)) ?></h2>
        <a class="btn btn-secondary" href="<?= $esc($link('mese', $successivo)) ?>" rel="next">
            <?= $esc(Agenda::nomeMese($successivo)) ?> &rarr;
        </a>
    </nav>

    <?php /* `agenda-mese` e' un contenitore: sotto i 36 rem di spazio la
             griglia diventa un elenco dei soli giorni che hanno qualcosa.
             Sette colonne in 320 px darebbero caselle di 40 px, dove un
             titolo non ci sta e un bersaglio da toccare nemmeno. */ ?>
    <div class="agenda-mese">
        <table class="agenda-griglia" role="table">
            <caption class="sr-only">
                <?= $esc(Agenda::nomeMese($mese)) ?>: i giorni con un incontro o l'apertura di un modulo.
            </caption>
            <thead role="rowgroup">
                <tr role="row">
                    <?php foreach (Agenda::nomiGiorni() as $g): ?>
                        <th scope="col" role="columnheader">
                            <span aria-hidden="true"><?= $esc(mb_substr($g, 0, 3)) ?></span>
                            <span class="sr-only"><?= $esc($g) ?></span>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody role="rowgroup">
                <?php foreach ($settimane as $settimana): ?>
                    <tr role="row">
                        <?php foreach ($settimana as $casella): ?>
                            <?php
                            $g = $casella['giorno'];
                            $oggi = $g->format('Y-m-d') === $adesso->format('Y-m-d');
                            ?>
                            <td role="cell"
                                class="<?= $casella['fuori'] ? 'fuori-mese' : '' ?> <?= $oggi ? 'oggi' : '' ?>">
                                <span class="agenda-numero"<?= $oggi ? ' aria-label="Oggi, ' . (int) $g->format('j') . '"' : '' ?>>
                                    <?= (int) $g->format('j') ?>
                                </span>
                                <?php foreach ($casella['eventi'] as $e): ?>
                                    <?php /* `title`: la tipologia compare passandoci sopra
                                             con il mouse. E' l'attributo del browser e non
                                             una finestrella disegnata da noi, che dentro a
                                             una cella di tabella e' la cosa che prima o poi
                                             qualcosa ritaglia (vedi la 0089). Non si vede
                                             da tastiera ne' col dito: per quelli ci sono la
                                             legenda qui sotto e il testo nascosto nel
                                             collegamento. */ ?>
                                    <a class="agenda-pillola agenda-<?= $esc((string) $e['tipo']) ?>"
                                       title="<?= $esc(Agenda::etichettaTipo((string) $e['tipo'])) ?>"
                                       href="<?= $esc((string) $e['url']) ?>">
                                        <span class="agenda-pillola-ora"><?= $esc($e['inizio']->format('H:i')) ?></span>
                                        <?= $esc((string) $e['titolo']) ?>
                                        <span class="sr-only">
                                            — <?= $esc(Agenda::etichettaTipo((string) $e['tipo'])) ?>
                                        </span>
                                    </a>
                                <?php endforeach; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php /* La legenda sta **sotto** alla griglia e non sopra: si guarda
             la prima volta, o quando un colore non torna, non a ogni
             visita. Le due voci sono le stesse di `Agenda::etichettaTipo()`,
             cosi' la legenda non puo' chiamare le cose in un modo diverso
             da come le chiama l'elenco.

             E' una legenda, non una decorazione: senza, il colore sarebbe
             l'unico modo di distinguere i due tipi, che e' proprio quello
             che il criterio 1.4.1 delle WCAG chiede di non fare. Il
             quadratino e' `aria-hidden`: a chi legge con un lettore di
             schermo non dice niente, e il testo accanto dice tutto. */ ?>
    <ul class="agenda-legenda">
        <?php foreach ([Agenda::INCONTRO, Agenda::APERTURA] as $tipo): ?>
            <li>
                <span class="agenda-segno agenda-<?= $esc($tipo) ?>" aria-hidden="true"></span>
                <?= $esc(Agenda::etichettaTipo($tipo)) ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php /* Il calendario esterno sta in fondo: si configura una volta sola,
         e in cima ruberebbe spazio a quello che si guarda ogni giorno. */ ?>
<section class="card agenda-calendario">
    <h2>Calendario personale</h2>

    <?php if ($calendario === null): ?>
        <p>
            Puoi aggiungere questa agenda a Google Calendar, a Calendario di Apple o a
            Outlook: si aggiorna da sola, e i nuovi incontri compaiono lì senza che tu
            debba rifare niente.
        </p>
        <p class="hint">
            Verrà creato un indirizzo personale. <strong>Chi ha quell'indirizzo vede i tuoi
            impegni</strong> senza bisogno di entrare in Pistacchio: trattalo come una
            password, e se ti sfugge puoi rigenerarlo da qui — il collegamento vecchio
            smette di funzionare.
        </p>
        <form method="post" action="/agenda/calendario" class="agenda-crea">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-primary">Crea l'indirizzo del calendario</button>
        </form>
    <?php else: ?>
        <?php $indirizzo = Url::to('/calendario/' . $calendario['token'] . '.ics'); ?>
        <p>Incolla questo indirizzo nel tuo calendario, alla voce «iscriviti a un calendario»:</p>
        <p><code class="agenda-indirizzo"><?= $esc($indirizzo) ?></code></p>

        <?php
        /*
         * Le due date dicono quello che serve per decidere se tenere
         * l'indirizzo o rigenerarlo: da quando esiste, e se qualcuno lo
         * sta ancora leggendo. Una lettura che non ci si spiega è il
         * segnale per cui il pulsante «Rigenera» sta lì sotto.
         *
         * Chi aveva creato l'indirizzo prima che le date esistessero non
         * ce le ha: lo si dice, invece di inventare un giorno.
         */
        $quando = static function (?string $valore) use ($esc): string {
            if ($valore === null || $valore === '') {
                return 'data non registrata';
            }

            $t = strtotime($valore);

            return $t === false ? 'data non registrata' : $esc(date('d/m/Y \a\l\l\e H:i', $t));
        };
        ?>
        <dl class="agenda-dati">
            <div>
                <dt>Creato</dt>
                <dd><?= $quando($calendario['creato']) ?></dd>
            </div>
            <div>
                <dt>Ultima lettura</dt>
                <dd>
                    <?= $calendario['usato'] === null && $calendario['creato'] !== null
                        ? 'mai: il calendario non è ancora stato aperto da nessun programma'
                        : $quando($calendario['usato']) ?>
                </dd>
            </div>
        </dl>

        <p class="hint">
            <strong>Chi ha questo indirizzo vede i tuoi impegni</strong> senza entrare in
            Pistacchio. Se la data di lettura non ti torna, o se lo hai mandato a qualcuno
            per sbaglio, rigeneralo: quello vecchio smette di funzionare subito.
        </p>
        <div class="form-actions">
            <form method="post" action="/agenda/calendario">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-secondary">Rigenera l'indirizzo</button>
            </form>
            <form method="post" action="/agenda/calendario/dimentica">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-secondary">Disattiva</button>
            </form>
        </div>
    <?php endif; ?>
</section>
