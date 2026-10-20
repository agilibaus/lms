<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\TreParole;

/**
 * «Tre parole» alla fine di un modulo (21/10, Alessandro): il riquadro nella
 * pagina del corso, sopra i moduli, per il modulo completato piu' di recente
 * a cui lo studente non ha ancora risposto. Resta finche' non risponde, ma
 * non blocca niente (`App\Core\TreParole`).
 *
 * Dopo l'invio, al suo posto, un «grazie» una volta sola. Un invio con una
 * casella vuota torna qui con le parole scritte e la frase che dice cosa
 * manca.
 *
 * @var array{chiedi: ?array, mie: array<int, array>, esito: ?array} $treParole
 * @var array<string, mixed> $course
 */

$esito = $treParole['esito'];
$modulo = $treParole['chiedi'];
?>
<?php if ($esito !== null && ($esito['tipo'] ?? '') === 'grazie'): ?>
    <div class="alert alert-success tre-parole-grazie" id="tre-parole" role="status">
        Grazie: le tue tre parole per «<?= htmlspecialchars((string) $esito['modulo']) ?>» sono arrivate.
    </div>
<?php endif; ?>

<?php if ($modulo !== null): ?>
    <?php
    $errore = $esito !== null && ($esito['tipo'] ?? '') === 'errore' && (int) ($esito['module_id'] ?? 0) === (int) $modulo['id'];
    $vecchie = $errore ? (array) ($esito['parole'] ?? []) : [];
    $nomi = ['Prima parola', 'Seconda parola', 'Terza parola'];
    ?>
    <section class="tre-parole" id="tre-parole" aria-labelledby="tre-parole-titolo">
        <h2 id="tre-parole-titolo">Tre parole per «<?= htmlspecialchars((string) $modulo['title']) ?>»</h2>
        <p>Hai completato il modulo. Scrivi le prime tre parole che ti vengono: sensazioni, emozioni, stati d’animo, pensieri, immagini.</p>
        <?php if ($errore): ?>
            <p class="tre-parole-errore" role="alert">Scrivi tutte e tre le parole.</p>
        <?php endif; ?>
        <form action="/courses/<?= (int) $course['id'] ?>/moduli/<?= (int) $modulo['id'] ?>/tre-parole" method="post" class="tre-parole-campi">
            <?= Csrf::field() ?>
            <?php foreach ($nomi as $i => $nome): ?>
                <label class="sr-only" for="tre-parole-<?= $i ?>"><?= $nome ?></label>
                <input type="text" id="tre-parole-<?= $i ?>" name="parole[<?= $i ?>]" placeholder="<?= $nome ?>"
                       maxlength="<?= TreParole::MASSIMO ?>" required autocomplete="off" spellcheck="false"
                       value="<?= htmlspecialchars((string) ($vecchie[$i] ?? '')) ?>"
                       aria-describedby="tre-parole-nota">
            <?php endforeach; ?>
            <button type="submit" class="btn btn-primary">Invia</button>
        </form>
        <p class="tre-parole-nota" id="tre-parole-nota">Il tuo tutor e l’amministratore possono leggerle. Una volta inviate non si cambiano.</p>
    </section>
<?php endif; ?>
