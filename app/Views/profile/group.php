<?php

declare(strict_types=1);

use App\Core\CourseCover;
use App\Core\GroupCircle;

/** @var array $group */
/** @var array|null $tutor */
/** @var array $people */

$quanti = count($people);
$posizioni = GroupCircle::posizioni($quanti);

/*
 * La foto, o le iniziali quando manca. `alt` vuoto perche' il nome e' scritto
 * subito accanto: ripeterlo farebbe dire ogni nome due volte a un lettore di
 * schermo.
 */
$foto = static function (array $persona): string {
    if (!empty($persona['avatar_path'])) {
        return '<img class="avatar persona-foto" src="/utenti/' . (int) $persona['id'] . '/immagine" alt="" loading="lazy">';
    }

    return '<span class="avatar avatar-placeholder persona-foto" aria-hidden="true">'
        . htmlspecialchars(CourseCover::initials((string) $persona['full_name']))
        . '</span>';
};
?>
<div class="page-header">
    <a href="/profilo" class="back-link">&larr; Profilo</a>
    <h1><?= htmlspecialchars((string) $group['name']) ?></h1>
    <p class="page-subtitle">
        <?= $quanti === 1 ? '1 partecipante' : $quanti . ' partecipanti' ?>
        <?php if ($tutor !== null): ?>
            · tutor: <?= htmlspecialchars((string) $tutor['full_name']) ?>
        <?php endif; ?>
    </p>
</div>

<?php if ($quanti === 0 && $tutor === null): ?>
    <p class="empty-state">Questo gruppo non ha ancora partecipanti.</p>
<?php else: ?>
    <?php /*
     * Un elenco solo, nello stesso ordine per tutti: il tutor prima, poi i
     * partecipanti in ordine di nome. E' la griglia del telefono; su un
     * computer, con spazio a sufficienza e non piu' di
     * GroupCircle::MASSIMO partecipanti, il CSS dispone le stesse voci in
     * cerchio usando le coordinate scritte qui sotto. Senza query di
     * contenitore resta la griglia, che e' il ripiego.
     *
     * `role="list"` perche' togliere i pallini a un elenco ne fa perdere la
     * semantica in Safari.
     */ ?>
    <div class="gruppo-persone">
        <ul class="gruppo-cerchio<?= GroupCircle::usaCerchio($quanti) ? ' usa-cerchio' : '' ?>" role="list">
            <?php if ($tutor !== null): ?>
                <li class="persona persona-tutor">
                    <?= $foto($tutor) ?>
                    <span class="persona-nome">
                        <?= htmlspecialchars((string) $tutor['full_name']) ?>
                        <span class="persona-ruolo">tutor</span>
                    </span>
                </li>
            <?php endif; ?>

            <?php foreach ($people as $i => $persona): ?>
                <?php $pos = $posizioni[$i]; ?>
                <li class="persona persona-lato-<?= $pos['lato'] ?>"
                    style="--x: <?= sprintf('%.2F', $pos['x']) ?>; --y: <?= sprintf('%.2F', $pos['y']) ?>;">
                    <?= $foto($persona) ?>
                    <span class="persona-nome"><?= htmlspecialchars((string) $persona['full_name']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
