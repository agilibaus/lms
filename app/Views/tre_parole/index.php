<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Core\TreParole;

/**
 * «Tre parole» (21/10, Alessandro): le parole che gli studenti scrivono alla
 * fine di un modulo, per il tutor del gruppo e per l'amministrazione, come
 * spunti per l'incontro. Le regole in `App\Core\TreParole`.
 *
 * DUE VISTE. Si apre sulle **anonime** (le parole che tornano e le terne in
 * un ordine che non dice chi le ha scritte): se il tutor la apre mentre
 * condivide lo schermo nel Meet, i nomi non compaiono per sbaglio. **Con i
 * nomi** e' un clic: una riga per studente, e in fondo chi ha completato il
 * modulo ma non ha ancora risposto.
 *
 * @var array<string, mixed> $corso
 * @var list<array<string, mixed>> $moduli
 * @var int $numero
 * @var array<string, mixed>|null $modulo
 * @var array<int, string> $gruppi
 * @var int|null $gruppo
 * @var string $vista
 * @var list<array<string, mixed>> $righe
 * @var list<array{user_id: int, full_name: string, completato_il: string}> $aCuiChiedere
 */

$esc = static fn (?string $v): string => htmlspecialchars((string) $v);
$corsoId = (int) $corso['id'];

/** L'indirizzo della pagina con le scelte fatte, cambiandone una. */
$indirizzo = static function (array $cambia) use ($corsoId, $numero, $gruppo, $vista): string {
    $q = array_merge([
        'corso' => $corsoId,
        'modulo' => $numero,
        'gruppo' => $gruppo,
        'vista' => $vista === TreParole::NOMI ? TreParole::NOMI : null,
    ], $cambia);

    return '/tre-parole?' . http_build_query(array_filter($q, static fn ($v): bool => $v !== null));
};

$terne = array_map(static fn (array $r): array => [(string) $r['word1'], (string) $r['word2'], (string) $r['word3']], $righe);
$ricorrenti = TreParole::ricorrenti($terne);
$risposto = array_map(static fn (array $r): int => (int) $r['user_id'], $righe);
$mancano = array_values(array_filter($aCuiChiedere, static fn (array $s): bool => !in_array($s['user_id'], $risposto, true)));
$quanti = count($righe) + count($mancano);
$attivo = !empty($corso['three_words_since']);
?>
<div class="page-header">
    <a href="/courses/<?= $corsoId ?>" class="back-link"><span class="back-link-testo"><?= $esc((string) $corso['title']) ?></span></a>
    <h1>Tre parole</h1>
    <p class="page-subtitle">Le parole degli studenti alla fine di ogni modulo: spunti per l’incontro che segue.</p>
    <?php if (!$attivo): ?>
        <p class="page-subtitle">
            Il corso non chiede le tre parole: si attivano in
            <?php if (Auth::can('course.edit')): ?>
                <a href="/admin/courses/<?= $corsoId ?>/edit">Modifica corso</a>.
            <?php else: ?>
                Modifica corso.
            <?php endif; ?>
        </p>
    <?php endif; ?>
</div>

<?php if ($moduli === []): ?>
    <p class="empty-state">Il corso non ha ancora moduli.</p>
<?php else: ?>
    <?php /* Collegamenti, non tendine: l'indirizzo dice che cosa si sta
             guardando, come nella griglia del corso. */ ?>
    <div class="griglia-filtri">
        <span class="griglia-filtri-etichetta" id="tp-vista">Vista</span>
        <nav class="agenda-viste" aria-labelledby="tp-vista">
            <?php foreach ([TreParole::ANONIME => 'Anonime', TreParole::NOMI => 'Con i nomi'] as $v => $etichetta): ?>
                <a href="<?= $esc($indirizzo(['vista' => $v === TreParole::NOMI ? $v : null])) ?>" class="<?= $vista === $v ? 'attiva' : '' ?>"
                   <?= $vista === $v ? 'aria-current="page"' : '' ?>><?= $etichetta ?></a>
            <?php endforeach; ?>
        </nav>
        <span class="griglia-filtri-etichetta" id="tp-modulo">Modulo</span>
        <nav class="agenda-viste" aria-labelledby="tp-modulo">
            <?php foreach ($moduli as $i => $m): ?>
                <a href="<?= $esc($indirizzo(['modulo' => $i + 1])) ?>" title="<?= $esc((string) $m['title']) ?>"
                   class="<?= $numero === $i + 1 ? 'attiva' : '' ?>" <?= $numero === $i + 1 ? 'aria-current="page"' : '' ?>>
                    <span class="sr-only">Modulo </span><?= $i + 1 ?><span class="sr-only">: <?= $esc((string) $m['title']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php if (count($gruppi) > 1): ?>
            <span class="griglia-filtri-etichetta" id="tp-gruppo">Gruppo</span>
            <nav class="agenda-viste" aria-labelledby="tp-gruppo">
                <a href="<?= $esc($indirizzo(['gruppo' => null])) ?>" class="<?= $gruppo === null ? 'attiva' : '' ?>"
                   <?= $gruppo === null ? 'aria-current="page"' : '' ?>>Tutti</a>
                <?php foreach ($gruppi as $id => $nome): ?>
                    <a href="<?= $esc($indirizzo(['gruppo' => $id])) ?>" class="<?= $gruppo === $id ? 'attiva' : '' ?>"
                       <?= $gruppo === $id ? 'aria-current="page"' : '' ?>><?= $esc($nome) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    </div>

    <section class="card tre-parole-lettura" aria-labelledby="tp-titolo">
        <h2 id="tp-titolo">
            Modulo <?= $numero ?> · <?= $esc((string) $modulo['title']) ?>
            <?php if ($gruppo !== null): ?>
                · Gruppo <?= $esc($gruppi[$gruppo]) ?>
            <?php endif; ?>
        </h2>

        <?php if ($righe === []): ?>
            <p class="empty-state">Ancora nessuna risposta per questo modulo.</p>
            <?php if ($vista === TreParole::NOMI && $mancano !== []): ?>
                <p class="tp-quanti">
                    Hanno completato il modulo e non hanno ancora risposto:
                    <?= $esc(implode(', ', array_column($mancano, 'full_name'))) ?>.
                </p>
            <?php endif; ?>
        <?php else: ?>
            <p class="tp-quanti">
                <strong><?= count($righe) ?></strong>
                <?= $quanti === 1 ? 'studente ha risposto' : 'studenti su ' . $quanti . ' hanno risposto' ?>
            </p>

            <h3 class="tp-sottotitolo">Le parole che tornano</h3>
            <ul class="tp-ricorrenti" role="list">
                <?php foreach ($ricorrenti as $r): ?>
                    <li><?= $esc($r['parola']) ?><?php if ($r['volte'] > 1): ?> <span class="tp-volte">×<?= $r['volte'] ?><span class="sr-only"> volte</span></span><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>

            <?php if ($vista === TreParole::ANONIME): ?>
                <h3 class="tp-sottotitolo">Le terne</h3>
                <?php /* In un ordine che non dice chi ha risposto prima (ne' chi
                         e' iscritto prima): `TreParole::mescola`. Niente date. */ ?>
                <ul class="tp-terne" role="list">
                    <?php foreach (TreParole::mescola($righe, $modulo['id'] . '|' . ($gruppo ?? 'tutti')) as $r): ?>
                        <li><?= $esc($r['word1']) ?> · <?= $esc($r['word2']) ?> · <?= $esc($r['word3']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <h3 class="tp-sottotitolo">Chi le ha scritte</h3>
                <div class="tabella-schede">
                    <table class="data-table" role="table">
                        <thead role="rowgroup">
                            <tr role="row">
                                <th scope="col" role="columnheader">Studente</th>
                                <th scope="col" role="columnheader">Le tre parole</th>
                                <th scope="col" role="columnheader">Il</th>
                            </tr>
                        </thead>
                        <tbody role="rowgroup">
                            <?php foreach ($righe as $r): ?>
                                <tr role="row">
                                    <td role="cell" data-label="Studente"><?= $esc((string) $r['full_name']) ?></td>
                                    <td role="cell" data-label="Le tre parole"><?= $esc($r['word1']) ?> · <?= $esc($r['word2']) ?> · <?= $esc($r['word3']) ?></td>
                                    <td role="cell" data-label="Il"><?= $esc(date('d/m/Y', (int) strtotime((string) $r['created_at']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php foreach ($mancano as $s): ?>
                                <tr role="row" class="tp-manca">
                                    <td role="cell" data-label="Studente"><?= $esc($s['full_name']) ?></td>
                                    <td role="cell" data-label="Le tre parole">non ha ancora risposto</td>
                                    <td role="cell" data-label="Il"><span class="valore-assente">—</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
