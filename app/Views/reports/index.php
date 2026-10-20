<?php

declare(strict_types=1);

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
 * @var bool $restricted
 */
?>
<div class="page-header">
    <h1>Report</h1>
    <p class="page-subtitle">
        Cinque tagli degli stessi dati, dal contenitore più grande al più piccolo.
        Ognuno si apre, si cerca e si scarica in XLSX o in CSV.
    </p>
    <?php if ($restricted): ?>
        <p class="page-subtitle">
            Stai vedendo solo gli studenti dei gruppi seguiti dal tuo tutor di riferimento.
        </p>
    <?php endif; ?>
</div>

<div class="report-scelta">
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
