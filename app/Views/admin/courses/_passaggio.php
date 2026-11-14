<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Quando;

/**
 * Il passaggio della bozza (14/11, Alessandro): un riquadro in cima alla
 * scheda, con una frase e al piu' un comando. La bozza e' presso il
 * supervisore o presso l'Amministratore; chi non la ha la legge soltanto. Un
 * solo passaggio, dal supervisore all'Amministratore: «Invia
 * all'Amministratore» (il supervisore) o «Riprendi il corso»
 * (l'Amministratore). Tutti e due chiedono una conferma, perche' non si torna
 * indietro. Per una bozza creata dall'Amministratore il riquadro non c'e':
 * basta lo stato sotto il titolo.
 *
 * @var array $course
 * @var string $presso
 * @var bool $puoInviare
 * @var bool $puoRiprendere
 * @var bool $supervisore
 */

$courseId = (int) $course['id'];
$passaggio = (string) ($course['passaggio'] ?? '');
$quando = !empty($course['passato_il']) ? Quando::dataOra((string) $course['passato_il']) : '';
$frase = null;

if ($presso === 'supervisore') {
    if ($puoInviare) {
        $frase = 'Il corso è presso di te: lo modifichi tu, l’Amministratore lo può solo leggere. Quando è pronto, '
            . 'invialo all’Amministratore: riceverà un’email, e da quel momento lo modificherà lui e tu lo potrai solo leggere.';
    } elseif ($puoRiprendere) {
        $frase = 'Ci sta lavorando il supervisore: puoi leggerlo, non modificarlo. Se devi intervenire prima che te lo invii, '
            . 'riprendilo: da quel momento lo modifichi tu, e il supervisore lo potrà solo leggere e riceverà un’email.';
    }
} elseif ($passaggio !== '') {
    $ripresa = $passaggio === 'ripresa';
    $frase = $supervisore
        ? ($ripresa ? 'L’Amministratore l’ha ripreso il ' . $quando : 'L’hai inviato all’Amministratore il ' . $quando)
            . ': lo puoi leggere, non modificare.'
        : ($ripresa ? 'L’hai ripreso il ' . $quando : 'Il supervisore te l’ha inviato il ' . $quando)
            . ': lo modifichi tu, e lo pubblichi quando è pronto.';
}
?>
<?php if ($frase !== null): ?>
<section class="card bozza-passaggio" aria-label="Presso chi è la bozza">
    <p><?= htmlspecialchars($frase) ?></p>
    <?php if ($puoInviare): ?>
        <form action="/admin/courses/<?= $courseId ?>/invia" method="post"
              onsubmit="return confirm('Dopo l’invio non potrai più modificare il corso. Inviarlo all’Amministratore?');">
            <?= Csrf::field() ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Invia all’Amministratore</button>
            </div>
        </form>
    <?php elseif ($puoRiprendere): ?>
        <form action="/admin/courses/<?= $courseId ?>/riprendi" method="post"
              onsubmit="return confirm('Il supervisore non potrà più modificare il corso. Riprenderlo?');">
            <?= Csrf::field() ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Riprendi il corso</button>
            </div>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>
