<?php

declare(strict_types=1);

use App\Core\GroupLogo;
use App\Core\Icona;
use App\Core\Ordinamento;
use App\Core\StudentiFermi;
use App\Core\Xlsx;

/**
 * «Studenti fermi» (20/10, Alessandro): chi non avanza in un corso, dal piu'
 * fermo. Una riga per studente e per corso. Le regole stanno in
 * `App\Core\StudentiFermi`.
 *
 * @var int $soglia
 * @var Ordinamento $ordine
 * @var list<array<string, mixed>> $righe
 * @var bool $restricted
 */

$esc = static fn (?string $v): string => htmlspecialchars((string) $v);

/**
 * L'ordine scelto viaggia con la soglia e con lo scaricamento: chi ha
 * ordinato per corso e passa da 14 a 30 giorni si aspetta lo stesso ordine,
 * e il file deve avere le righe come le vede.
 */
$conOrdine = static function (array $q) use ($ordine): string {
    if ($ordine->chiave() !== null) {
        $q += ['ordina' => $ordine->chiave(), 'verso' => $ordine->verso()];
    }

    return http_build_query($q);
};

/** Il gruppo con il suo simbolo davanti, come negli elenchi dei Report. */
$gruppo = static function (array $g) use ($esc): string {
    $logo = GroupLogo::url($g);
    $simbolo = $logo !== null
        ? '<img src="' . $esc($logo) . '" alt="" class="group-logo" loading="lazy">'
        : '<span class="group-logo group-logo-placeholder" aria-hidden="true" style="--logo-hue: '
            . GroupLogo::hue((int) $g['id']) . ';">' . $esc(GroupLogo::initials((string) $g['name'])) . '</span>';

    return '<span class="group-name">' . $simbolo . $esc((string) $g['name']) . '</span>';
};

/**
 * Le tre frasi della legenda, con la soglia scelta dentro.
 */
$legenda = [
    StudentiFermi::MAI => 'nessuna attività dall’iscrizione.',
    StudentiFermi::ENTRA => 'ha aperto il corso negli ultimi ' . $soglia . ' giorni, ma senza fare niente.',
    StudentiFermi::ESCE => 'non apre il corso da almeno ' . $soglia . ' giorni.',
];
?>
<div class="page-header">
    <a href="/reports" class="back-link"><span class="back-link-testo">Report</span></a>
    <h1>Studenti fermi</h1>
    <p class="page-subtitle">
        Chi non ha attività nel corso da almeno il numero di giorni scelto, o non l’ha mai iniziato.
        Esclusi i corsi completati.
    </p>
    <?php if ($restricted): ?>
        <p class="page-subtitle">Stai vedendo solo gli studenti dei gruppi che segui.</p>
    <?php endif; ?>
    <?php /* Come nel report del corso: XLSX per aprirlo con un doppio clic,
             CSV per un altro programma. Con la soglia e l'ordine scelti. */ ?>
    <?php if ($righe !== []): ?>
        <p>
            <details class="dropdown">
                <summary class="btn btn-secondary btn-con-icona"><?= Icona::svg('download') ?>Scarica</summary>
                <div class="dropdown-menu">
                    <?php if (Xlsx::disponibile()): ?>
                        <a href="/reports/fermi/xlsx?<?= $esc($conOrdine(['giorni' => (string) $soglia])) ?>">XLSX</a>
                    <?php endif; ?>
                    <a href="/reports/fermi/csv?<?= $esc($conOrdine(['giorni' => (string) $soglia])) ?>">CSV</a>
                </div>
            </details>
        </p>
    <?php endif; ?>
</div>

<?php /* Tre soglie, come «Elenco / Mese» nell'Agenda: collegamenti, non una
         tendina, e quella attiva si riconosce anche da `aria-current`. */ ?>
<nav class="agenda-viste" aria-label="Fermi da quanti giorni">
    <?php foreach (StudentiFermi::SOGLIE as $n): ?>
        <a href="/reports/fermi?<?= $esc($conOrdine(['giorni' => (string) $n])) ?>" class="<?= $n === $soglia ? 'attiva' : '' ?>"
           <?= $n === $soglia ? 'aria-current="page"' : '' ?>><?= $n ?> giorni</a>
    <?php endforeach; ?>
</nav>

<section class="card">
    <?php if ($righe === []): ?>
        <p class="empty-state">Nessuno studente fermo da almeno <?= $soglia ?> giorni.</p>
    <?php else: ?>
        <p class="report-conteggio">
            <strong><?= count($righe) ?></strong>
            <?= count($righe) === 1 ? 'studente fermo' : 'studenti fermi' ?> da almeno <?= $soglia ?> giorni
        </p>
        <?php /* Nove colonne: sotto i 50 rem diventano schede (vedi
                 `.tabella-schede`). Tutte si ordinano tranne i comandi;
                 senza una scelta, dal piu' fermo. */ ?>
        <div class="tabella-schede fermi">
            <table class="data-table" role="table">
                <thead role="rowgroup">
                    <tr role="row">
                        <?= $ordine->th('Studente', 'studente') ?>
                        <?= $ordine->th('Corso', 'corso') ?>
                        <?= $ordine->th('Gruppo', 'gruppo') ?>
                        <?= $ordine->th('Iscritto da', 'iscritto') ?>
                        <?= $ordine->th('Ultima attività', 'attivita') ?>
                        <?= $ordine->th('Ultima visita', 'visita') ?>
                        <?= $ordine->th('Stato', 'stato') ?>
                        <?= $ordine->th('Avanzamento', 'avanzamento', 'col-numero') ?>
                        <th scope="col" role="columnheader"><span class="sr-only">Azioni</span></th>
                    </tr>
                </thead>
                <tbody role="rowgroup">
                    <?php foreach ($righe as $r): ?>
                        <tr role="row">
                            <td role="cell" data-label="Studente"><?= $esc((string) $r['full_name']) ?></td>
                            <td role="cell" data-label="Corso"><?= $esc((string) $r['course_title']) ?></td>
                            <td role="cell" data-label="Gruppo">
                                <?php if ($r['gruppi'] === []): ?>
                                    <span class="valore-assente">—</span>
                                <?php else: ?>
                                    <?= implode('<br>', array_map($gruppo, $r['gruppi'])) ?>
                                <?php endif; ?>
                            </td>
                            <td role="cell" data-label="Iscritto da"><?= (int) $r['iscritto_giorni'] ?> <?= (int) $r['iscritto_giorni'] === 1 ? 'giorno' : 'giorni' ?></td>
                            <td role="cell" data-label="Ultima attività">
                                <?php if ($r['ultima_at'] === null): ?>
                                    <span class="valore-assente">—</span>
                                <?php else: ?>
                                    <?= $esc(date('d/m', (int) strtotime((string) $r['ultima_at']))) ?> · <?= $esc((string) $r['ultima_cosa']) ?>
                                <?php endif; ?>
                            </td>
                            <td role="cell" data-label="Ultima visita">
                                <?php /* Vuota per chi non e' piu' passato dal corso da
                                         quando si registra (migrazione del 20/10). */ ?>
                                <?= $r['visita_giorni'] === null ? '<span class="valore-assente">—</span>' : $esc(StudentiFermi::quando((int) $r['visita_giorni'])) ?>
                            </td>
                            <td role="cell" data-label="Stato">
                                <?php /* Solo il pallino (21/10, Alessandro); che cosa
                                         vuol dire lo dice la legenda sotto la tabella,
                                         il passaggio del mouse e, per chi non vede il
                                         colore, il testo nascosto. */ ?>
                                <?php $etichetta = StudentiFermi::etichettaStato((string) $r['stato']); ?>
                                <span class="pallino pallino-<?= $esc((string) $r['stato']) ?>" title="<?= $esc($etichetta) ?>" aria-hidden="true"></span>
                                <span class="sr-only"><?= $esc($etichetta) ?></span>
                            </td>
                            <td role="cell" data-label="Avanzamento" class="col-numero"><?= (int) round((float) $r['progress_pct']) ?>%</td>
                            <td role="cell" class="row-actions">
                                <a href="/reports/students/<?= (int) $r['user_id'] ?>">Dettaglio</a>
                                <?php /* L'email gia' indirizzata, con il corso nell'oggetto:
                                         il resto lo scrive il tutor. */ ?>
                                <a href="mailto:<?= $esc((string) $r['email']) ?>?subject=<?= rawurlencode((string) $r['course_title']) ?>">Scrivi</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php /* Un elenco di definizioni: il pallino e il nome sono il
                 termine, la spiegazione e' la definizione. */ ?>
        <dl class="fermi-legenda">
            <?php foreach ($legenda as $stato => $spiegazione): ?>
                <div>
                    <dt><span class="pallino pallino-<?= $esc($stato) ?>" aria-hidden="true"></span><span><?= $esc(ucfirst(StudentiFermi::etichettaStato($stato))) ?>:</span></dt>
                    <dd><?= $esc($spiegazione) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>
</section>
