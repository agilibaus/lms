<?php

declare(strict_types=1);

use App\Core\Csrf;

/**
 * «Ricalcolo dei voti» (16/11, Alessandro, mockup approvato): prima di
 * salvare una modifica che cambia i voti di un questionario, che cosa
 * cambia. «Salva e ricalcola» rimanda gli stessi campi del modulo con la
 * conferma; «Annulla» torna alla pagina da cui si veniva, senza salvare.
 *
 * @var array $quiz
 * @var string $cosa      la modifica, in una frase
 * @var string $cambia    «il punteggio» o «l'esito»
 * @var array{tentativi: list<int>, studenti: list<int>, perdono: list<int>, superano: list<int>,
 *            righe: list<array{user_id: int, prima: float, prima_superato: bool, dopo: float, dopo_superato: bool}>} $riepilogo
 * @var array<int, string> $nomi
 * @var bool $obbligatorio il modulo ha il questionario obbligatorio
 * @var array<string, mixed> $campi i campi del modulo da rimandare
 * @var string $azione
 * @var string $annulla
 */

$n = count($riepilogo['tentativi']);
$m = count($riepilogo['studenti']);
$perdono = count($riepilogo['perdono']);
$superano = count($riepilogo['superano']);
$righe = $riepilogo['righe'];

usort($righe, static fn (array $a, array $b): int => strcasecmp($nomi[$a['user_id']] ?? '', $nomi[$b['user_id']] ?? ''));

$esito = static fn (float $pct, bool $superato): string => number_format($pct, 0) . '%';
?>
<div class="page-header">
    <a href="/quizzes/<?= (int) $quiz['id'] ?>/edit" class="back-link"><span class="back-link-testo"><?= htmlspecialchars((string) $quiz['title']) ?></span></a>
    <h1>Ricalcolo dei voti</h1>
</div>

<section class="card">
    <p style="margin-top: 0;"><?= htmlspecialchars($cosa) ?></p>

    <p>
        <strong>Il ricalcolo cambia <?= htmlspecialchars($cambia) ?> di
            <?= $n === 1 ? '1 tentativo' : $n . ' tentativi' ?>
            di <?= $m === 1 ? '1 studente' : $m . ' studenti' ?>.</strong>
        <?php if ($perdono > 0): ?>
            <?= $perdono === $m && $m > 1
                ? 'Tutti e ' . $m . ' non risultano più aver superato il questionario.'
                : ($perdono === 1 ? '1 studente non risulta più aver superato il questionario.' : $perdono . ' studenti non risultano più aver superato il questionario.') ?>
            <?php if ($obbligatorio): ?>
                Il questionario è obbligatorio: per <?= $perdono === 1 ? 'questo studente' : 'loro' ?> i moduli successivi si richiudono.
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($superano > 0): ?>
            <?= $superano === 1 ? '1 studente ora lo supera.' : $superano . ' studenti ora lo superano.' ?>
        <?php endif; ?>
        I certificati già emessi restano.
    </p>

    <?php if ($righe !== []): ?>
        <div class="tabella-schede">
            <table class="data-table" role="table">
                <thead role="rowgroup">
                <tr role="row">
                    <th scope="col" role="columnheader">Studente</th>
                    <th scope="col" role="columnheader">Miglior risultato, prima</th>
                    <th scope="col" role="columnheader">Dopo il ricalcolo</th>
                </tr>
                </thead>
                <tbody role="rowgroup">
                <?php foreach ($righe as $riga): ?>
                    <tr role="row">
                        <td role="cell" data-label="Studente"><?= htmlspecialchars($nomi[$riga['user_id']] ?? '—') ?></td>
                        <td role="cell" data-label="Prima">
                            <?= $esito($riga['prima'], $riga['prima_superato']) ?> ·
                            <?= $riga['prima_superato'] ? 'superato' : 'non superato' ?>
                        </td>
                        <td role="cell" data-label="Dopo">
                            <?= $esito($riga['dopo'], $riga['dopo_superato']) ?> ·
                            <?php if ($riga['dopo_superato'] !== $riga['prima_superato']): ?>
                                <strong><?= $riga['dopo_superato'] ? 'superato' : 'non superato' ?></strong>
                            <?php else: ?>
                                <?= $riga['dopo_superato'] ? 'superato' : 'non superato' ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="form-hint">
            Nella tabella gli studenti il cui miglior risultato cambia. Nessuna email agli studenti: il nuovo
            esito lo vedono nella pagina del questionario.
        </p>
    <?php else: ?>
        <p class="form-hint">
            Il miglior risultato di ogni studente resta lo stesso. Nessuna email agli studenti.
        </p>
    <?php endif; ?>

    <form action="<?= htmlspecialchars($azione) ?>" method="post" class="form">
        <?= Csrf::field() ?>
        <input type="hidden" name="conferma_ricalcolo" value="1">
        <?php foreach ($campi as $nome => $valore): ?>
            <?php if (is_array($valore)): ?>
                <?php foreach ($valore as $v): ?>
                    <input type="hidden" name="<?= htmlspecialchars((string) $nome) ?>[]" value="<?= htmlspecialchars((string) $v) ?>">
                <?php endforeach; ?>
            <?php else: ?>
                <input type="hidden" name="<?= htmlspecialchars((string) $nome) ?>" value="<?= htmlspecialchars((string) $valore) ?>">
            <?php endif; ?>
        <?php endforeach; ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Salva e ricalcola</button>
            <a href="<?= htmlspecialchars($annulla) ?>" class="btn btn-secondary">Annulla</a>
        </div>
    </form>
</section>
