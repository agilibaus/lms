<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Ordinamento;

/** @var array $session */
/** @var bool $canManage */
/** @var array $participants */
/** @var array<int, array{joined_at: string|null, source: string}> $attendance */
/** @var bool $hasJoined */

$id = (int) $session['id'];
$startsAt = new DateTimeImmutable((string) $session['starts_at']);
$endsAt = new DateTimeImmutable((string) $session['ends_at']);
$now = new DateTimeImmutable('now');

/*
 * La presenza non sta nella riga del partecipante: sta in `$attendance`,
 * indicizzata per identificativo. Per ordinarci sopra si porta dentro alla
 * riga, invece di insegnare all'ordinamento a guardare in un secondo
 * elenco — sarebbe una cosa in piu' che puo' rompersi, per una pagina sola.
 */
$participants = array_map(
    static function (array $p) use ($attendance): array {
        $r = $attendance[(int) $p['id']] ?? null;

        return $p + [
            'ingresso' => $r === null ? null : $r['joined_at'],
            'origine' => $r === null || $r['joined_at'] === null ? null : $r['source'],
        ];
    },
    $participants
);

$ordine = Ordinamento::daRichiesta([
    'partecipante' => ['full_name', Ordinamento::TESTO],
    'ingresso' => ['ingresso', Ordinamento::DATA],
    'origine' => ['origine', Ordinamento::TESTO],
]);
$participants = $ordine->applica($participants);
$isPast = $endsAt < $now;
?>
<div class="page-header">
    <a href="/live" class="back-link">&larr; Sessioni live</a>
    <h1><?= htmlspecialchars((string) $session['title']) ?></h1>
    <p class="page-subtitle">
        <?= htmlspecialchars($startsAt->format('d/m/Y H:i')) ?>–<?= htmlspecialchars($endsAt->format('H:i')) ?>
        <?php if (!empty($session['course_title'])): ?>
            · <?= htmlspecialchars((string) $session['course_title']) ?> / <?= htmlspecialchars((string) $session['module_title']) ?>
        <?php endif; ?>
        <?php if (!empty($session['group_name'])): ?>
            · Gruppo <?= htmlspecialchars((string) $session['group_name']) ?>
        <?php endif; ?>
    </p>
</div>

<?php require __DIR__ . '/../admin/_flash.php'; ?>

<?php if (!empty($session['description'])): ?>
    <p class="course-description"><?= nl2br(htmlspecialchars((string) $session['description'])) ?></p>
<?php endif; ?>

<section class="card">
    <h2>Collegamento</h2>

    <?php if (!empty($session['meet_link'])): ?>
        <p class="card-meta">
            <?= !empty($session['google_event_id'])
                ? 'Link creato da Google Calendar insieme all’evento.'
                : 'Link inserito manualmente: questa sessione non è sincronizzata con Google Calendar.' ?>
        </p>
        <?php if ($isPast): ?>
            <p class="empty-state-small">La sessione è conclusa.</p>
        <?php else: ?>
            <?php /* La riunione si apre in una seconda scheda: chiudendo Meet si
                     ritrova Pistacchio dov'era, senza dover tornare indietro.
                     Google non offre un modo per rimandare al mittente chi esce
                     dalla riunione, quindi la scheda separata e' l'unico ritorno
                     che non dipende da un gesto dell'utente. */ ?>
            <p>
                <a href="/live/<?= $id ?>/join" class="btn btn-primary"
                   target="_blank" rel="noopener">Entra nella sessione</a>
            </p>
            <p class="form-hint">Si apre in una nuova scheda: questa pagina resta aperta.</p>
        <?php endif; ?>
        <?php if ($hasJoined): ?>
            <p class="form-hint">Il tuo ingresso è stato registrato.</p>
        <?php endif; ?>
    <?php else: ?>
        <p class="empty-state-small">Nessun link Meet associato a questa sessione.</p>
        <?php if ($canManage): ?>
            <form action="/live/<?= $id ?>/sync" method="post" class="form-inline">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-secondary">Crea l’evento su Google Calendar</button>
            </form>
            <p class="form-hint">In alternativa, inserisci un link manuale dalla pagina di modifica.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($canManage && !empty($session['meet_link']) && !$isPast): ?>
    <section class="card">
        <h2>Inviti</h2>
        <p class="card-meta">
            Manda ai partecipanti attesi<?= $participants !== [] ? ' (' . count($participants) . ')' : '' ?>
            un’email con data, ora e collegamento, e il file da aggiungere al calendario.
            Puoi ripetere l’invio più tardi, per esempio dopo nuove iscrizioni:
            chi l’ha già ricevuto si ritrova la stessa voce aggiornata, non una seconda.
        </p>

        <form action="/live/<?= $id ?>/inviti" method="post"
              onsubmit="return confirm('Inviare l’invito a tutti i partecipanti attesi?');">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-secondary">Invia inviti</button>
        </form>
    </section>
<?php endif; ?>

<?php if ($canManage): ?>
    <section class="card">
        <h2>Presenze</h2>
        <p class="card-meta">
            L’ingresso viene registrato quando lo studente apre il Meet da questa piattaforma; qui puoi
            correggerlo o segnare le presenze a mano, anche a sessione conclusa.
        </p>

        <?php if ($participants === []): ?>
            <p class="empty-state-small">Nessun partecipante atteso: il modulo o il gruppo collegati non hanno iscritti.</p>
        <?php else: ?>
            <?php /* Sotto i 50 rem di spazio diventa un elenco di schede. */ ?>
            <div class="tabella-schede">
            <table class="data-table" role="table">
                <thead role="rowgroup">
                <tr role="row">
                    <?= $ordine->th('Partecipante', 'partecipante') ?>
                    <?= $ordine->th('Ingresso', 'ingresso') ?>
                    <?= $ordine->th('Origine', 'origine') ?>
                    <th scope="col" role="columnheader"><span class="sr-only">Azioni</span></th>
                </tr>
                </thead>
                <tbody role="rowgroup">
                <?php foreach ($participants as $participant): ?>
                    <?php
                    $record = $attendance[(int) $participant['id']] ?? null;
                    $present = $record !== null && $record['joined_at'] !== null;
                    ?>
                    <tr role="row">
                        <td role="cell" data-label="Partecipante">
                            <?= htmlspecialchars((string) $participant['full_name']) ?>
                            <span class="cell-sub"><?= htmlspecialchars((string) $participant['email']) ?></span>
                        </td>
                        <td role="cell" data-label="Ingresso">
                            <?php if ($present): ?>
                                <?= htmlspecialchars((new DateTimeImmutable((string) $record['joined_at']))->format('d/m/Y H:i')) ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td role="cell" data-label="Origine">
                            <?php if ($present): ?>
                                <span class="badge <?= $record['source'] === 'manual' ? '' : 'badge-success' ?>">
                                    <?= $record['source'] === 'manual' ? 'segnata dal tutor' : 'piattaforma' ?>
                                </span>
                            <?php else: ?>
                                <span class="badge badge-danger">assente</span>
                            <?php endif; ?>
                        </td>
                        <td role="cell" class="row-actions">
                            <form action="/live/<?= $id ?>/attendance/<?= (int) $participant['id'] ?>" method="post">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="present" value="<?= $present ? '0' : '1' ?>">
                                <button type="submit" class="link-btn">
                                    <?= $present ? 'Segna assente' : 'Segna presente' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2 class="danger-heading">Elimina sessione</h2>
        <p class="card-meta">Viene rimosso anche l’evento su Google Calendar, se collegato.</p>
        <form action="/live/<?= $id ?>/delete" method="post"
              onsubmit="return confirm('Eliminare questa sessione e le presenze registrate?');">
            <?= Csrf::field() ?>
            <button type="submit" class="link-btn link-btn-danger">Elimina definitivamente</button>
        </form>
    </section>
<?php endif; ?>
