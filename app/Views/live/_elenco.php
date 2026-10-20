<?php

declare(strict_types=1);

use App\Core\Icona;

/**
 * Le sessioni live viste da chi partecipa (20/10, Alessandro): gli stessi
 * riquadri dell'Agenda, non la tabella dello staff. In cima «In
 * programma», dalla piu' vicina; sotto «Concluse», chiuse come lo Storico
 * dell'Agenda, dalla piu' recente.
 *
 * Nel riquadro: data e orario, titolo, corso e modulo oppure gruppo. Il
 * modo di entrare (variante C2): finche' e' presto, «Si entra da 15 minuti
 * prima» sotto il titolo, perche' descrive la sessione; a destra solo i
 * comandi. Dentro la finestra «Entra» con la videocamera, e «in corso» a
 * incontro cominciato. «Aggiungi al calendario» solo finche' l'incontro
 * non e' cominciato: dopo non serve. Niente colonna «Meet»: come e' stato
 * creato il collegamento interessa lo staff.
 *
 * @var list<array<string, mixed>> $sessions
 */

$adesso = new DateTimeImmutable('now');
$future = [];
$concluse = [];

foreach ($sessions as $s) {
    if (new DateTimeImmutable((string) $s['ends_at']) < $adesso) {
        $concluse[] = $s;
    } else {
        $future[] = $s;
    }
}

usort($future, static fn (array $a, array $b): int => strcmp((string) $a['starts_at'], (string) $b['starts_at']));
usort($concluse, static fn (array $a, array $b): int => strcmp((string) $b['starts_at'], (string) $a['starts_at']));

$riquadro = static function (array $s) use ($adesso): string {
    $inizio = new DateTimeImmutable((string) $s['starts_at']);
    $fine = new DateTimeImmutable((string) $s['ends_at']);
    $passata = $fine < $adesso;
    // «in corso» e la finestra d'ingresso arrivano dalla query, come nella
    // tabella dello staff e nell'Agenda (`LiveSessionModel::FINESTRA_SELECT`).
    $inCorso = (bool) ($s['started'] ?? false);
    $apribile = !empty($s['meet_link']) && (bool) ($s['joinable'] ?? false);
    $id = (int) $s['id'];

    $contesto = [];
    if (!empty($s['course_title'])) {
        $contesto[] = $s['course_title'] . ' · ' . $s['module_title'];
    }
    if (!empty($s['group_name'])) {
        $contesto[] = 'Gruppo: ' . $s['group_name'];
    }

    $html = '<li class="agenda-voce agenda-incontro' . ($inCorso && !$passata ? ' agenda-in-corso' : '') . '">'
        . '<span class="agenda-quando"><span class="agenda-giorno">' . htmlspecialchars($inizio->format('d/m')) . '</span> '
        . '<span class="agenda-ora">' . htmlspecialchars($inizio->format('H:i') . '–' . $fine->format('H:i')) . '</span></span>'
        . '<span class="agenda-cosa"><a href="/live/' . $id . '">' . htmlspecialchars((string) $s['title']) . '</a>';

    foreach ($contesto as $riga) {
        $html .= '<span class="agenda-contesto">' . htmlspecialchars((string) $riga) . '</span>';
    }

    if (!$passata && !$apribile && !empty($s['meet_link'])) {
        $html .= '<span class="agenda-attesa">Si entra da 15 minuti prima</span>';
    }

    $html .= '</span><span class="agenda-azioni">';

    if (!$passata) {
        // «Entra» per ultimo, appoggiato al margine destro, su computer e su
        // telefono; «Aggiungi al calendario» alla sua sinistra (20/10,
        // Alessandro). Lo stesso ordine nell'Agenda.
        if ($inCorso) {
            $html .= '<span class="badge badge-success">in corso</span>';
        }
        if (!$inCorso) {
            $html .= '<a href="/agenda/evento/' . $id . '.ics">Aggiungi al calendario</a>';
        }
        if ($apribile) {
            // In una scheda nuova, come dalla pagina della sessione: questa
            // resta aperta.
            $html .= '<a class="btn btn-primary btn-con-icona" href="/live/' . $id . '/join" target="_blank" rel="noopener">'
                . Icona::svg('camera-video') . 'Entra<span class="sr-only"> (si apre in una nuova scheda)</span></a>';
        }
    }

    return $html . '</span></li>';
};
?>
<section class="agenda-gruppo">
    <h2>In programma</h2>
    <?php if ($future === []): ?>
        <p class="empty-state">Nessuna sessione in programma.</p>
    <?php else: ?>
        <ul class="agenda-elenco">
            <?php foreach ($future as $s): ?>
                <?= $riquadro($s) ?>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php if ($concluse !== []): ?>
    <details class="agenda-passati">
        <summary><h2>Concluse (<?= count($concluse) ?>)</h2></summary>
        <ul class="agenda-elenco">
            <?php foreach ($concluse as $s): ?>
                <?= $riquadro($s) ?>
            <?php endforeach; ?>
        </ul>
    </details>
<?php endif; ?>
