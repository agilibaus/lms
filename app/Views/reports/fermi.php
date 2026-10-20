<?php

declare(strict_types=1);

use App\Core\StudentiFermi;

/**
 * «Studenti fermi» (20/10, Alessandro): chi non avanza in un corso, dal piu'
 * fermo. Una riga per studente e per corso. Le regole stanno in
 * `App\Core\StudentiFermi`.
 *
 * @var int $soglia
 * @var list<array<string, mixed>> $righe
 * @var bool $restricted
 */

$esc = static fn (?string $v): string => htmlspecialchars((string) $v);
$classeStato = [
    StudentiFermi::MAI => 'badge-muted',
    StudentiFermi::ENTRA => 'badge-attenzione',
    StudentiFermi::ESCE => 'badge-danger',
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
</div>

<?php /* Tre soglie, come «Elenco / Mese» nell'Agenda: collegamenti, non una
         tendina, e quella attiva si riconosce anche da `aria-current`. */ ?>
<nav class="agenda-viste" aria-label="Fermi da quanti giorni">
    <?php foreach (StudentiFermi::SOGLIE as $n): ?>
        <a href="/reports/fermi?giorni=<?= $n ?>" class="<?= $n === $soglia ? 'attiva' : '' ?>"
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
        <?php /* Sette colonne: sotto i 50 rem diventano schede (vedi
                 `.tabella-schede`). */ ?>
        <div class="tabella-schede fermi">
            <table class="data-table" role="table">
                <thead role="rowgroup">
                    <tr role="row">
                        <th scope="col" role="columnheader">Studente</th>
                        <th scope="col" role="columnheader">Corso</th>
                        <th scope="col" role="columnheader">Ultima attività</th>
                        <th scope="col" role="columnheader">Ultima visita al corso</th>
                        <th scope="col" role="columnheader">Stato</th>
                        <th scope="col" role="columnheader" class="col-numero">Avanzamento</th>
                        <th scope="col" role="columnheader"><span class="sr-only">Azioni</span></th>
                    </tr>
                </thead>
                <tbody role="rowgroup">
                    <?php foreach ($righe as $r): ?>
                        <tr role="row">
                            <td role="cell" data-label="Studente">
                                <?= $esc((string) $r['full_name']) ?>
                                <span class="cell-sub"><?= $esc((string) $r['email']) ?></span>
                            </td>
                            <td role="cell" data-label="Corso">
                                <?= $esc((string) $r['course_title']) ?>
                                <?php if ($r['gruppi'] !== []): ?>
                                    <span class="cell-sub"><?= count($r['gruppi']) === 1 ? 'Gruppo' : 'Gruppi' ?> <?= $esc(implode(', ', $r['gruppi'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td role="cell" data-label="Ultima attività">
                                <?php if ($r['ultima_at'] === null): ?>
                                    <span class="valore-assente">—</span>
                                    <span class="cell-sub">iscritto da <?= (int) $r['giorni'] ?> giorni</span>
                                <?php else: ?>
                                    <?= $esc(date('d/m', (int) strtotime((string) $r['ultima_at']))) ?> · <?= $esc((string) $r['ultima_cosa']) ?>
                                    <span class="cell-sub"><?= $esc(StudentiFermi::quando((int) $r['giorni'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td role="cell" data-label="Ultima visita al corso">
                                <?php /* Vuota per chi non e' piu' passato dal corso da
                                         quando si registra (migrazione del 20/10). */ ?>
                                <?= $r['visita_giorni'] === null ? '<span class="valore-assente">—</span>' : $esc(StudentiFermi::quando((int) $r['visita_giorni'])) ?>
                            </td>
                            <td role="cell" data-label="Stato">
                                <span class="badge <?= $classeStato[$r['stato']] ?>"><?= $esc(StudentiFermi::etichettaStato((string) $r['stato'])) ?></span>
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
    <?php endif; ?>
</section>
