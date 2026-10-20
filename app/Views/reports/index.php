<?php

declare(strict_types=1);

use App\Core\StudentiFermi;

/**
 * L'indice dei report: sceglie il taglio, non lo stampa.
 *
 * Prima qui c'erano cinque tabelle intere, una sotto l'altra. Con 605
 * studenti la pagina era alta **22.042 px** — ventidue schermi — e per
 * sapere quali tagli esistessero bisognava scorrerli tutti. Adesso sono
 * cinque riquadri con il proprio numero: in uno schermo si vede che cosa
 * c'e' e quanto, e si apre quello che serve.
 *
 * @var array<string, array<string, mixed>> $sezioni
 * @var array<string, int> $conteggi
 * @var int $fermi
 * @var bool $restricted
 */
?>
<div class="page-header">
    <h1>Report</h1>
    <p class="page-subtitle">
        Gli studenti da contattare e cinque tagli degli stessi dati, dal contenitore più grande al più
        piccolo; i tagli si aprono, si cercano e si scaricano in XLSX o in CSV.
    </p>
    <?php if ($restricted): ?>
        <p class="page-subtitle">
            Stai vedendo solo gli studenti dei gruppi seguiti dal tuo tutor di riferimento.
        </p>
    <?php endif; ?>
</div>

<div class="report-scelta">
    <?php /* «Studenti fermi» per primo (20/10): e' il report da cui si agisce,
             gli altri si consultano. Il numero e' quello a 14 giorni, la
             soglia con cui la pagina si apre. */ ?>
    <a class="report-taglio" href="/reports/fermi">
        <span class="report-taglio-titolo">
            Studenti fermi
            <span class="report-taglio-numero">(<?= (int) $fermi ?>)</span>
        </span>
        <span class="report-taglio-nota">Chi non avanza da <?= StudentiFermi::SOGLIA_INIZIALE ?> giorni o non ha mai iniziato: da contattare per primi.</span>
    </a>
    <?php foreach ($sezioni as $chiave => $s): ?>
        <?php $quanti = $conteggi[$chiave] ?? 0; ?>
        <a class="report-taglio" href="/reports/elenco/<?= htmlspecialchars($chiave) ?>">
            <?php /* Il nome e, accanto, quanti sono (20/10, Alessandro, variante
                     A): «Corsi (4)», con il numero nella misura e nel colore di
                     prima. E' la cosa che si cerca aprendo la pagina, e cosi'
                     si legge insieme al nome invece che su una riga a parte. */ ?>
            <span class="report-taglio-titolo">
                <?= htmlspecialchars($s['titolo']) ?>
                <span class="report-taglio-numero">(<?= (int) $quanti ?>)</span>
            </span>
            <span class="report-taglio-nota"><?= htmlspecialchars($s['occhiello']) ?></span>
        </a>
    <?php endforeach; ?>
</div>
