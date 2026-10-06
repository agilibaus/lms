<?php

declare(strict_types=1);

use App\Controllers\GroupPageController;
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

/*
 * LA PRESENTAZIONE (06/10, chiesto da Elena). Chi l'ha scritta diventa un
 * pulsante — foto e nome insieme — che apre una scheda sopra la pagina con
 * l'attributo `popovertarget`: niente JavaScript, e il browser gestisce da
 * se' Esc, il clic fuori e il ritorno del fuoco. Il cerchio non si sposta.
 *
 * Le schede stanno **dopo** l'elenco, non dentro le voci: un browser che
 * non conosce i popover le mostra come un elenco di presentazioni sotto i
 * partecipanti, ed e' il ripiego. Dentro le voci rovinerebbero il cerchio.
 *
 * Chi ne ha una lo dice un segno a forma di fumetto sull'angolo della foto
 * — la forma, non un colore (§4) — e, per un lettore di schermo, il testo
 * nascosto «leggi la presentazione» nel nome del pulsante.
 */
$haPresentazione = static fn (array $persona): bool => trim((string) ($persona['bio'] ?? '')) !== '';

$voce = static function (array $persona, string $nome) use ($foto, $haPresentazione): string {
    if (!$haPresentazione($persona)) {
        return $foto($persona) . $nome;
    }

    return '<button type="button" class="persona-apri" popovertarget="presentazione-' . (int) $persona['id'] . '">'
        . '<span class="persona-foto-cornice">' . $foto($persona)
        . '<span class="persona-fumetto" aria-hidden="true"></span></span>'
        . $nome
        . '</button>';
};

$conPresentazione = array_values(array_filter(
    array_merge($tutor !== null ? [$tutor] : [], $people),
    $haPresentazione
));
?>
<div class="page-header">
    <a href="/profilo" class="back-link">&larr; Profilo</a>
    <h1><?= htmlspecialchars(GroupPageController::titolo((string) $group['name'])) ?></h1>
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
                    <?= $voce($tutor, '<span class="persona-nome">'
                        . htmlspecialchars((string) $tutor['full_name'])
                        . ($haPresentazione($tutor) ? '<span class="sr-only">, leggi la presentazione</span>' : '')
                        . '<span class="persona-ruolo">tutor</span></span>') ?>
                </li>
            <?php endif; ?>

            <?php foreach ($people as $i => $persona): ?>
                <?php $pos = $posizioni[$i]; ?>
                <li class="persona persona-lato-<?= $pos['lato'] ?>"
                    style="--x: <?= sprintf('%.2F', $pos['x']) ?>; --y: <?= sprintf('%.2F', $pos['y']) ?>;">
                    <?= $voce($persona, '<span class="persona-nome">'
                        . htmlspecialchars((string) $persona['full_name'])
                        . ($haPresentazione($persona) ? '<span class="sr-only">, leggi la presentazione</span>' : '')
                        . '</span>') ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <?php if ($conPresentazione !== []): ?>
        <div class="presentazioni">
            <?php foreach ($conPresentazione as $persona): ?>
                <?php $pid = 'presentazione-' . (int) $persona['id']; ?>
                <section class="presentazione" id="<?= $pid ?>" popover aria-labelledby="<?= $pid ?>-nome">
                    <div class="presentazione-testa">
                        <?= $foto($persona) ?>
                        <h2 id="<?= $pid ?>-nome"><?= htmlspecialchars((string) $persona['full_name']) ?></h2>
                        <?php /* Il comando per chiudere c'e' solo dove il
                                 popover funziona: nel ripiego la scheda e'
                                 una sezione della pagina, e non c'e' niente
                                 da chiudere. */ ?>
                        <button type="button" class="presentazione-chiudi"
                                popovertarget="<?= $pid ?>" popovertargetaction="hide">
                            <span aria-hidden="true">&times;</span>
                            <span class="sr-only">Chiudi</span>
                        </button>
                    </div>
                    <p class="presentazione-testo"><?= nl2br(htmlspecialchars(trim((string) $persona['bio'])), false) ?></p>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
