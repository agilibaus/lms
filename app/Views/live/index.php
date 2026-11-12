<?php

declare(strict_types=1);

use App\Core\Ordinamento;
use App\Core\Icona;

/** @var array $sessions */
/** @var bool $canManage */
/** @var bool $tabella la tabella dello staff: chi organizza, e l'assistente in sola lettura (26/10) */
/** @var bool $googleConfigured */

$now = new DateTimeImmutable('now');

// «Destinatari» non si ordina: la cella mostra il corso **oppure** il
// gruppo, e sono due campi diversi. Ordinare su uno metterebbe in fondo
// tutte le righe dell'altro, con l'aria di un difetto.
$ordine = Ordinamento::daRichiesta([
    'quando' => ['starts_at', Ordinamento::DATA],
    'sessione' => ['title', Ordinamento::TESTO],
]);
$sessions = $ordine->applica($sessions);
?>
<div class="page-header">
    <h1>Sessioni live</h1>
    <p class="page-subtitle">
        <?= $canManage
            ? 'Incontri su Google Meet collegati a un modulo di corso o a un gruppo.'
            : ($tabella ? 'Gli incontri dei gruppi che segui con il tuo tutor, in sola lettura.' : 'Gli incontri dal vivo dei tuoi corsi e gruppi.') ?>
    </p>
    <?php if ($canManage): ?>
        <p><a href="/live/create" class="btn btn-primary btn-con-icona"><?= Icona::svg('plus-lg') ?>Nuova sessione</a></p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../admin/_flash.php'; ?>

<?php if ($canManage && !$googleConfigured): ?>
    <div class="alert alert-warning">
        Google Calendar non è configurato: le sessioni si creano comunque, ma il link Meet va inserito a mano.
        Vedi la sezione “Sessioni live (Google Meet)” del manuale (manuale.md).
    </div>
<?php endif; ?>

<?php if ($sessions === []): ?>
    <p class="empty-state">Nessuna sessione in programma.</p>
<?php elseif (!$tabella): ?>
    <?php /* Chi partecipa vede i riquadri dell'Agenda (20/10); la tabella
             e' per chi organizza. */ ?>
    <?php /* `live-riquadri`: qui data e ora stanno su una riga (06/11). */ ?>
    <div class="live-riquadri">
        <?php require __DIR__ . '/_elenco.php'; ?>
    </div>
<?php else: ?>
    <?php /* Sei colonne. Sopra i 50 rem di spazio la tabella, ordinabile.
             Sotto (il telefono, 30/10, Alessandro, disposizione B) un
             elenco a parte, `live-schede`, piu' sotto: prima le prossime,
             poi le concluse chiuse, con la scheda corretta. Lo spazio di
             `live-elenco` decide quale dei due si vede. */ ?>
    <?php /* Nel riquadro bianco, come i report (30/10, Alessandro): ogni elenco sta in un riquadro. */ ?>
    <div class="live-elenco card">
    <div class="tabella-schede live-tabella">
    <table class="data-table" role="table">
        <thead role="rowgroup">
        <tr role="row">
            <?= $ordine->th('Quando', 'quando') ?>
            <?php /* Le parole delle schede del telefono anche qui (30/10,
                     Alessandro, forma 1): «Stato» in una colonna sua, che
                     non va mai a capo, e «Link Meet» per intero. */ ?>
            <th scope="col" role="columnheader">Stato</th>
            <?= $ordine->th('Sessione', 'sessione') ?>
            <th scope="col" role="columnheader">Destinatari</th>
            <th scope="col" role="columnheader">Link Meet</th>
            <th scope="col" role="columnheader">Accesso</th>
            <th scope="col" role="columnheader"><span class="sr-only">Azioni</span></th>
        </tr>
        </thead>
        <tbody role="rowgroup">
        <?php foreach ($sessions as $session): ?>
            <?php
            $startsAt = new DateTimeImmutable((string) $session['starts_at']);
            $endsAt = new DateTimeImmutable((string) $session['ends_at']);
            // «in corso» e la finestra d'ingresso arrivano dalla query
            // (`LiveSessionModel::FINESTRA_SELECT`): una definizione sola
            // per tutte le pagine, e calcolata dall'orologio del database.
            $isLive = (bool) ($session['started'] ?? false);
            $apribile = (bool) ($session['joinable'] ?? false);
            $isPast = $endsAt < $now;
            ?>
            <tr role="row" class="<?= $isPast ? 'row-past' : '' ?>">
                <td role="cell" data-label="Quando">
                    <?= htmlspecialchars($startsAt->format('d/m/Y H:i')) ?>–<?= htmlspecialchars($endsAt->format('H:i')) ?>
                </td>
                <td role="cell" data-label="Stato" class="live-stato">
                    <?= $isLive ? '<strong class="live-in-corso">In corso</strong>' : ($isPast ? 'Conclusa' : 'In programma') ?>
                </td>
                <td role="cell" data-label="Sessione"><a href="/live/<?= (int) $session['id'] ?>"><?= htmlspecialchars((string) $session['title']) ?></a></td>
                <td role="cell" data-label="Destinatari">
                    <?php if (!empty($session['course_title'])): ?>
                        <span class="cell-line"><?= htmlspecialchars((string) $session['course_title']) ?> · <?= htmlspecialchars((string) $session['module_title']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($session['group_name'])): ?>
                        <span class="cell-line">Gruppo: <?= htmlspecialchars((string) $session['group_name']) ?></span>
                    <?php endif; ?>
                </td>
                <td role="cell" data-label="Link Meet">
                    <?php if (!empty($session['meet_link'])): ?>
                        <?= !empty($session['google_event_id']) ? 'Creato da Google Calendar' : 'Inserito a mano' ?>
                    <?php else: ?>
                        <span class="live-assente">Assente</span>
                    <?php endif; ?>
                </td>
                <?php /* La colonna dice sempre **come si entra**, e cambia
                         contenuto invece di apparire e sparire: «Da 15
                         minuti prima» finche' e' presto, poi il
                         collegamento vero. Fuori dalla finestra non c'e' un
                         comando spento: un collegamento senza `href` non e'
                         piu' un collegamento — non prende il fuoco col
                         tabulatore e un lettore di schermo non lo annuncia
                         — e il grigio da solo non dice perche'.

                         Il testo sta nella misura della tabella e non in
                         `.cell-sub`, che e' la riga **secondaria** di una
                         cella: qui non c'e' nessuna riga principale sopra,
                         e da sola diventerebbe un sottotitolo senza titolo
                         (vedi §5). */ ?>
                <td role="cell" data-label="Accesso">
                    <?php if (!empty($session['meet_link']) && $apribile): ?>
                        <a href="/live/<?= (int) $session['id'] ?>/join" class="btn btn-primary btn-con-icona live-entra"
                           target="_blank" rel="noopener"><?= Icona::svg('camera-video') ?>Entra</a>
                    <?php elseif (!empty($session['meet_link']) && !$isPast): ?>
                        Da 15 minuti prima
                    <?php elseif (!$isPast): ?>
                        Impossibile senza link
                    <?php endif; ?>
                </td>
                <td role="cell" class="row-actions">
                    <?php if ($canManage && \App\Core\LiveScope::gestibile($session)): ?>
                        <a href="/live/<?= (int) $session['id'] ?>/edit" class="comando-con-icona"><?= Icona::svg('pencil') ?>Modifica</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php
    // Il telefono: le prossime dalla piu' vicina, le concluse dalla piu'
    // recente. L'ordine fisso fa il lavoro di «Quando» e «Sessione», che qui
    // non ci sono (proposta 1).
    $prossime = [];
    $concluse = [];
    foreach ($sessions as $session) {
        if (new DateTimeImmutable((string) $session['ends_at']) < $now) {
            $concluse[] = $session;
        } else {
            $prossime[] = $session;
        }
    }
    usort($prossime, static fn (array $a, array $b): int => strcmp((string) $a['starts_at'], (string) $b['starts_at']));
    usort($concluse, static fn (array $a, array $b): int => strcmp((string) $b['starts_at'], (string) $a['starts_at']));

    // Una scheda: «Stato» sotto «Quando», «Link Meet» per intero, e
    // «Accesso» solo finche' la sessione non e' conclusa.
    $scheda = static function (array $session) use ($now, $canManage): string {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
        $startsAt = new DateTimeImmutable((string) $session['starts_at']);
        $endsAt = new DateTimeImmutable((string) $session['ends_at']);
        $isLive = (bool) ($session['started'] ?? false);
        $apribile = (bool) ($session['joinable'] ?? false);
        $isPast = $endsAt < $now;
        $conLink = !empty($session['meet_link']);
        $id = (int) $session['id'];

        $stato = $isLive ? '<strong class="live-in-corso">In corso</strong>' : ($isPast ? 'Conclusa' : 'In programma');
        $link = !$conLink ? '<span class="live-assente">Assente</span>'
            : (!empty($session['google_event_id']) ? 'Creato da Google Calendar' : 'Inserito a mano');
        $destinatari = '';
        if (!empty($session['course_title'])) {
            $destinatari .= '<span class="cell-line">' . $e((string) $session['course_title']) . ' · ' . $e((string) $session['module_title']) . '</span>';
        }
        if (!empty($session['group_name'])) {
            $destinatari .= '<span class="cell-line">Gruppo: ' . $e((string) $session['group_name']) . '</span>';
        }

        $html = '<tr role="row" class="' . ($isPast ? 'row-past' : '') . '">'
            . '<td role="cell" data-label="Quando">' . $e($startsAt->format('d/m/Y H:i')) . '–' . $e($endsAt->format('H:i')) . '</td>'
            . '<td role="cell" data-label="Stato">' . $stato . '</td>'
            . '<td role="cell" data-label="Sessione"><a href="/live/' . $id . '">' . $e((string) $session['title']) . '</a></td>'
            . '<td role="cell" data-label="Destinatari">' . $destinatari . '</td>'
            . '<td role="cell" data-label="Link Meet">' . $link . '</td>';

        if (!$isPast) {
            $accesso = !$conLink ? 'Impossibile senza link'
                : ($apribile
                    ? '<a href="/live/' . $id . '/join" class="btn btn-primary btn-con-icona live-entra" target="_blank" rel="noopener">'
                        . Icona::svg('camera-video') . 'Entra</a>'
                    : 'Da 15 minuti prima');
            $html .= '<td role="cell" data-label="Accesso">' . $accesso . '</td>';
        }

        $html .= '<td role="cell" class="row-actions">';
        if ($canManage && \App\Core\LiveScope::gestibile($session)) {
            $html .= '<a href="/live/' . $id . '/edit" class="comando-con-icona">' . Icona::svg('pencil') . 'Modifica</a>';
        }

        return $html . '</td></tr>';
    };

    // Le intestazioni ci sono per chi legge con un lettore di schermo, ma
    // non ordinano: in modalita' schede restano fuori dalla vista.
    $tabellaSchede = static function (array $elenco) use ($scheda): string {
        return '<div class="tabella-schede schede-senza-titolo"><table class="data-table" role="table"><thead role="rowgroup"><tr role="row">'
            . '<th scope="col" role="columnheader">Quando</th><th scope="col" role="columnheader">Stato</th>'
            . '<th scope="col" role="columnheader">Sessione</th><th scope="col" role="columnheader">Destinatari</th>'
            . '<th scope="col" role="columnheader">Link Meet</th><th scope="col" role="columnheader">Accesso</th>'
            . '<th scope="col" role="columnheader"><span class="sr-only">Azioni</span></th></tr></thead>'
            . '<tbody role="rowgroup">' . implode('', array_map($scheda, $elenco)) . '</tbody></table></div>';
    };
    ?>
    <div class="live-schede">
        <h2 class="live-gruppo">Prossime <span class="badge badge-muted"><?= count($prossime) ?></span></h2>
        <?php if ($prossime === []): ?>
            <p class="empty-state-small">Nessuna sessione in programma.</p>
        <?php else: ?>
            <?= $tabellaSchede($prossime) ?>
        <?php endif; ?>

        <?php if ($concluse !== []): ?>
            <details class="live-concluse">
                <summary class="live-gruppo">Concluse <span class="badge badge-muted"><?= count($concluse) ?></span>
                    <span class="card-meta">— le più recenti per prime</span></summary>
                <?= $tabellaSchede($concluse) ?>
            </details>
        <?php endif; ?>
    </div>
    </div>
<?php endif; ?>
