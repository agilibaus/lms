<?php

declare(strict_types=1);

use App\Core\GrigliaCorso;
use App\Core\Icona;
use App\Core\Ordinamento;
use App\Core\Xlsx;

/**
 * «Griglia del corso» (21/10, Alessandro): una riga per studente; una
 * colonna per modulo, o per attivita' del modulo aperto. Le regole in
 * `App\Core\GrigliaCorso`.
 *
 * @var array<string, mixed> $course
 * @var list<array{id: int, titolo: string, voci: list<array<string, mixed>>}> $moduli
 * @var array<int, string> $gruppi
 * @var int|null $gruppo
 * @var int|null $modulo
 * @var Ordinamento $ordine
 * @var list<array<string, mixed>> $studenti
 * @var bool $restricted
 */

$esc = static fn (?string $v): string => htmlspecialchars((string) $v);
$corsoId = (int) $course['id'];
$base = '/reports/courses/' . $corsoId . '/griglia';

/**
 * Un indirizzo della griglia che tiene le scelte fatte (modulo, gruppo,
 * ordine) e ne cambia una: chi ordina per avanzamento e apre un modulo si
 * aspetta di ritrovare lo stesso ordine.
 */
$indirizzo = static function (array $cambia, string $percorso = '') use ($base, $modulo, $gruppo, $ordine): string {
    $q = array_filter([
        'modulo' => $modulo,
        'gruppo' => $gruppo,
        'ordina' => $ordine->chiave(),
        'verso' => $ordine->chiave() === null ? null : $ordine->verso(),
    ], static fn ($v): bool => $v !== null);
    $q = array_filter(array_merge($q, $cambia), static fn ($v): bool => $v !== null);

    return $base . $percorso . ($q === [] ? '' : '?' . http_build_query($q));
};

/** Le caselle di uno studente per un modulo, nell'ordine delle attivita'. */
$caselleDel = static fn (array $studente, array $m): array
    => array_map(static fn (array $v): array => $studente['caselle'][$v['chiave']], $m['voci']);

$conta = ['lezione' => 0, 'incontro' => 0, 'questionario' => 0];

foreach ($moduli as $m) {
    foreach ($m['voci'] as $v) {
        $conta[$v['tipo']]++;
    }
}

$plurale = static fn (int $n, string $uno, string $tanti): string => $n . ' ' . ($n === 1 ? $uno : $tanti);
$tipoLeggibile = [
    GrigliaCorso::LEZIONE => 'Lezione',
    GrigliaCorso::INCONTRO => 'Incontro',
    GrigliaCorso::QUESTIONARIO => 'Questionario',
];

/**
 * Una casella del modulo aperto. La spunta e' l'icona del «completato»
 * (`check-circle-fill`, come nella pagina del corso) ed e' per gli occhi,
 * la parola nascosta per chi ascolta; il dettaglio (la data, i tentativi)
 * nel suggerimento al passaggio del mouse.
 */
$casella = static function (array $c) use ($esc): string {
    $quando = isset($c['quando']) && $c['quando'] !== null ? date('d/m/Y', (int) strtotime((string) $c['quando'])) : null;

    if (isset($c['punteggio'])) {
        $superato = $c['stato'] === GrigliaCorso::FATTO;
        $titolo = 'migliore di ' . (int) $c['tentativi'] . ' ' . ((int) $c['tentativi'] === 1 ? 'tentativo' : 'tentativi');

        return '<span class="griglia-voto' . ($superato ? '' : ' griglia-bocciato') . '" title="' . $esc($titolo) . '">'
            . $esc(GrigliaCorso::punteggio((float) $c['punteggio']))
            . ($superato ? ' <span class="griglia-fatto">' . Icona::svg('check-circle-fill') . '</span><span class="sr-only">, superato</span>' : '<span class="sr-only">, non superato</span>')
            . '</span>';
    }

    return match ($c['stato']) {
        GrigliaCorso::FATTO => '<span class="griglia-fatto"' . ($quando === null ? '' : ' title="' . $esc($quando) . '"') . '>' . Icona::svg('check-circle-fill') . '</span><span class="sr-only">fatto</span>',
        GrigliaCorso::ASSENTE => '<span class="griglia-assente">assente</span>',
        GrigliaCorso::FUTURO => '<span class="griglia-nulla" title="non ancora svolto" aria-hidden="true">—</span><span class="sr-only">non ancora svolto</span>',
        GrigliaCorso::NON_PREVISTO => '<span class="griglia-nulla" title="non previsto per lo studente" aria-hidden="true">—</span><span class="sr-only">non previsto</span>',
        default => '<span class="sr-only">non fatto</span>',
    };
};
?>
<div class="page-header">
    <a href="/reports/courses/<?= $corsoId ?>" class="back-link"><span class="back-link-testo"><?= $esc((string) $course['title']) ?></span></a>
    <h1>Griglia del corso</h1>
    <p class="page-subtitle">
        <?= $esc((string) $course['title']) ?> ·
        <?= $plurale(count($moduli), 'modulo', 'moduli') ?> ·
        <?= $plurale($conta['lezione'], 'lezione', 'lezioni') ?> ·
        <?= $plurale($conta['questionario'], 'questionario', 'questionari') ?> ·
        <?= $plurale($conta['incontro'], 'incontro', 'incontri') ?> ·
        <?= $plurale(count($studenti), 'iscritto', 'iscritti') ?>
    </p>
    <?php if ($restricted): ?>
        <p class="page-subtitle">Stai vedendo solo gli studenti dei gruppi che segui.</p>
    <?php endif; ?>
    <?php /* Il file ha sempre tutte le attivita' in colonna, qualunque modulo
             sia aperto: nel foglio si filtra meglio che a schermo. */ ?>
    <?php if ($studenti !== [] && $moduli !== []): ?>
        <p>
            <details class="dropdown">
                <summary class="btn btn-secondary btn-con-icona"><?= Icona::svg('download') ?>Scarica</summary>
                <div class="dropdown-menu">
                    <?php if (Xlsx::disponibile()): ?>
                        <a href="<?= $esc($indirizzo(['modulo' => null], '/xlsx')) ?>">XLSX</a>
                    <?php endif; ?>
                    <a href="<?= $esc($indirizzo(['modulo' => null], '/csv')) ?>">CSV</a>
                </div>
            </details>
        </p>
    <?php endif; ?>
</div>

<?php if ($moduli !== []): ?>
    <?php /* Collegamenti e non tendine, come le soglie di «Studenti fermi»:
             l'indirizzo dice che cosa si sta guardando. Il gruppo si sceglie
             solo se ce n'e' piu' d'uno. */ ?>
    <div class="griglia-filtri">
        <span class="griglia-filtri-etichetta" id="griglia-modulo">Modulo</span>
        <nav class="agenda-viste" aria-labelledby="griglia-modulo">
            <a href="<?= $esc($indirizzo(['modulo' => null])) ?>" class="<?= $modulo === null ? 'attiva' : '' ?>"
               <?= $modulo === null ? 'aria-current="page"' : '' ?>>Tutti</a>
            <?php foreach ($moduli as $n => $m): ?>
                <a href="<?= $esc($indirizzo(['modulo' => $n + 1])) ?>" title="<?= $esc($m['titolo']) ?>"
                   class="<?= $modulo === $n + 1 ? 'attiva' : '' ?>" <?= $modulo === $n + 1 ? 'aria-current="page"' : '' ?>>
                    <span class="sr-only">Modulo </span><?= $n + 1 ?><span class="sr-only">: <?= $esc($m['titolo']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php if (count($gruppi) > 1): ?>
            <span class="griglia-filtri-etichetta" id="griglia-gruppo">Gruppo</span>
            <nav class="agenda-viste" aria-labelledby="griglia-gruppo">
                <a href="<?= $esc($indirizzo(['gruppo' => null])) ?>" class="<?= $gruppo === null ? 'attiva' : '' ?>"
                   <?= $gruppo === null ? 'aria-current="page"' : '' ?>>Tutti</a>
                <?php foreach ($gruppi as $id => $nome): ?>
                    <a href="<?= $esc($indirizzo(['gruppo' => $id])) ?>" class="<?= $gruppo === $id ? 'attiva' : '' ?>"
                       <?= $gruppo === $id ? 'aria-current="page"' : '' ?>><?= $esc($nome) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    </div>
<?php endif; ?>

<section class="card">
    <?php if ($moduli === []): ?>
        <p class="empty-state">Il corso non ha ancora moduli.</p>
    <?php elseif ($studenti === []): ?>
        <p class="empty-state">Nessuno studente iscritto<?= $gruppo !== null ? ' in questo gruppo' : '' ?>.</p>
    <?php else: ?>
        <?php
        $aperto = $modulo === null ? null : $moduli[$modulo - 1];
        $etichettaGriglia = $aperto === null ? 'Griglia per moduli' : 'Modulo ' . $modulo . ': ' . $aperto['titolo'];
        ?>
        <?php /* La griglia scorre in orizzontale quando non ci sta, con il nome
                 dello studente fermo a sinistra. Si puo' raggiungere da
                 tastiera (`tabindex`) per scorrerla con le frecce. Qui non ci
                 sono tendine che il contenitore potrebbe tagliare. */ ?>
        <div class="griglia-scorre" role="region" aria-label="<?= $esc($etichettaGriglia) ?>" tabindex="0">
            <table class="griglia">
                <thead>
                    <?php if ($aperto === null): ?>
                        <tr>
                            <?= $ordine->th('Studente', 'studente', 'griglia-nome') ?>
                            <th scope="col" class="griglia-testo">Gruppo</th>
                            <?php foreach ($moduli as $n => $m): ?>
                                <th scope="col" class="griglia-modulo<?= $n === 0 ? ' griglia-separa' : '' ?>">
                                    <a href="<?= $esc($indirizzo(['modulo' => $n + 1])) ?>">
                                        <span class="griglia-numero">Modulo <?= $n + 1 ?></span>
                                        <?= $esc($m['titolo']) ?>
                                    </a>
                                </th>
                            <?php endforeach; ?>
                            <?= $ordine->th('Avanzamento', 'avanzamento', 'griglia-separa') ?>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td colspan="2" class="griglia-nome"></td>
                            <th scope="colgroup" colspan="<?= count($aperto['voci']) ?>" class="griglia-titolo-modulo griglia-separa">
                                Modulo <?= (int) $modulo ?> · <?= $esc($aperto['titolo']) ?>
                            </th>
                            <td class="griglia-separa"></td>
                        </tr>
                        <tr>
                            <?= $ordine->th('Studente', 'studente', 'griglia-nome') ?>
                            <th scope="col" class="griglia-testo">Gruppo</th>
                            <?php foreach ($aperto['voci'] as $j => $v): ?>
                                <th scope="col" class="griglia-voce<?= $j === 0 ? ' griglia-separa' : '' ?>">
                                    <span class="griglia-numero"><?= $tipoLeggibile[$v['tipo']] ?></span>
                                    <?= $esc($v['titolo']) ?>
                                </th>
                            <?php endforeach; ?>
                            <?= $ordine->th('Avanzamento', 'avanzamento', 'griglia-separa') ?>
                        </tr>
                    <?php endif; ?>
                </thead>
                <tbody>
                    <?php foreach ($studenti as $s): ?>
                        <tr>
                            <th scope="row" class="griglia-nome">
                                <a href="/reports/students/<?= (int) $s['user_id'] ?>"><?= $esc($s['full_name']) ?></a>
                            </th>
                            <td class="griglia-testo">
                                <?= $s['gruppi'] === [] ? '<span class="valore-assente">—</span>' : $esc(implode(', ', $s['gruppi'])) ?>
                            </td>
                            <?php if ($aperto === null): ?>
                                <?php foreach ($moduli as $n => $m): ?>
                                    <?php $c = GrigliaCorso::conteggio($caselleDel($s, $m)); ?>
                                    <td class="<?= $n === 0 ? 'griglia-separa' : '' ?>">
                                        <?php if ($c['totale'] === 0): ?>
                                            <span class="griglia-nulla" aria-hidden="true">—</span><span class="sr-only">niente da fare per ora</span>
                                        <?php elseif (GrigliaCorso::completo($c)): ?>
                                            <span class="griglia-fatto"><?= Icona::svg('check-circle-fill') ?></span><span class="sr-only">modulo completato</span>
                                        <?php else: ?>
                                            <span class="griglia-conto<?= $c['fatte'] === 0 ? ' griglia-zero' : '' ?>"><?= $c['fatte'] ?>/<?= $c['totale'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php foreach ($aperto['voci'] as $j => $v): ?>
                                    <td class="<?= $j === 0 ? 'griglia-separa' : '' ?>"><?= $casella($s['caselle'][$v['chiave']]) ?></td>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <td class="griglia-separa griglia-avanzamento">
                                <?= (int) round($s['progress_pct']) ?>%
                                <?php if ($s['certificato']): ?>
                                    <span class="cell-sub">certificato</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php /* La riga che dice dove ci si ferma: quanti hanno
                         completato ogni modulo, o fatto ogni attivita'. Si
                         conta su chi l'attivita' la doveva fare. */ ?>
                <tfoot>
                    <tr>
                        <th scope="row" colspan="2" class="griglia-nome"><?= $aperto === null ? 'Modulo completato' : 'Fatto da' ?></th>
                        <?php if ($aperto === null): ?>
                            <?php foreach ($moduli as $n => $m): ?>
                                <?php
                                $tocca = 0;
                                $completi = 0;
                                foreach ($studenti as $s) {
                                    $c = GrigliaCorso::conteggio($caselleDel($s, $m));
                                    $tocca += $c['totale'] > 0 ? 1 : 0;
                                    $completi += GrigliaCorso::completo($c) ? 1 : 0;
                                }
                                ?>
                                <td class="<?= $n === 0 ? 'griglia-separa' : '' ?>"><?= $tocca === 0 ? '—' : $completi . '/' . $tocca ?></td>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php foreach ($aperto['voci'] as $j => $v): ?>
                                <?php
                                $tocca = 0;
                                $fatte = 0;
                                foreach ($studenti as $s) {
                                    $stato = $s['caselle'][$v['chiave']]['stato'];
                                    $tocca += GrigliaCorso::conta($stato) ? 1 : 0;
                                    $fatte += $stato === GrigliaCorso::FATTO ? 1 : 0;
                                }
                                ?>
                                <td class="<?= $j === 0 ? 'griglia-separa' : '' ?>"><?= $tocca === 0 ? '—' : $fatte . '/' . $tocca ?></td>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <td class="griglia-separa"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <?php if ($aperto === null): ?>
            <p class="griglia-legenda">
                <span><strong>2/4</strong> attività del modulo fatte</span>
                <span><span class="griglia-fatto"><?= Icona::svg('check-circle-fill') ?></span> modulo completato</span>
                <span><strong aria-hidden="true">—</strong> nel modulo non c’è ancora niente da fare</span>
                <span>Gli incontri non ancora svolti non contano. Clicca un modulo per vederne le attività.</span>
            </p>
        <?php else: ?>
            <p class="griglia-legenda">
                <span><span class="griglia-fatto"><?= Icona::svg('check-circle-fill') ?></span> fatto</span>
                <span><strong>90%</strong> questionario: punteggio migliore, <span class="griglia-fatto"><?= Icona::svg('check-circle-fill') ?></span> se superato</span>
                <span><strong class="griglia-assente">assente</strong> all’incontro</span>
                <span><strong aria-hidden="true">—</strong> incontro non ancora svolto, o non previsto per lo studente</span>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<script src="/assets/js/dropdown.js"></script>
