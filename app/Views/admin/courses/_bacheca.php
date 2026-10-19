<?php

declare(strict_types=1);

use App\Core\Bacheca;
use App\Core\CampoContato;
use App\Core\Csrf;
use App\Core\Icona;

/**
 * Le categorie della Bacheca del corso (17/10), per chi le gestisce
 * (`board.manage`, l'admin). Nella bacheca sono i riquadri colorati della
 * prima pagina, in quest'ordine; il tutor sceglie fra queste quando
 * pubblica, non ne crea.
 *
 * Una categoria per riga, ripiegata: aperta, il suo modulo, lo spostamento
 * e l'eliminazione (solo se non ha risorse).
 *
 * @var array $course
 * @var list<array<string, mixed>> $bacheca
 */

$courseId = (int) $course['id'];
$ultima = count($bacheca) - 1;

// I colori del tema hanno la loro tinta (non l'89% di bianco): l'anteprima
// la usa come `Bacheca::colore()`.
$temi = [];

foreach (\App\Core\Theme::TAVOLOZZE as $t) {
    $temi[strtoupper($t['primary'])] = strtoupper($t['soft']);
}

/** I campi di una categoria: gli stessi per correggere e per aggiungere. */
$campiCategoria = static function (string $p, array $c) use ($temi): string {
    // Il colore (17/10, strada 2): una tavolozza continua, con un cerchio da
    // spostare (tinta in orizzontale, chiaro e scuro in verticale) e
    // l'intensita', e accanto l'anteprima del riquadro. Il valore che parte
    // e' quello del selettore di colore del browser, che senza JavaScript
    // resta l'unico comando e basta da solo; lo script disegna la tavolozza
    // e lo tiene allineato.
    $col = Bacheca::colore((string) ($c['color'] ?? 'verde'));
    $scelto = $col['primary'];
    $nome = (string) ($c['name'] ?? '');

    return '<label for="' . $p . '-nome">Nome</label>'
        . '<input type="text" id="' . $p . '-nome" name="name" required maxlength="' . Bacheca::MAX_NOME_CATEGORIA . '" value="'
        . htmlspecialchars($nome, ENT_QUOTES) . '">'
        . '<fieldset class="bacheca-colore-campo"><legend>Colore</legend>'
        . '<details class="bacheca-colore" data-temi="' . htmlspecialchars((string) json_encode($temi), ENT_QUOTES) . '"><summary>'
        . '<span class="bacheca-colore-campione" style="background:' . $scelto . '"></span>Cambia colore'
        . '<span class="bacheca-colore-freccia" aria-hidden="true"></span></summary>'
        . '<div class="bacheca-colore-pannello"><div class="bacheca-colore-comandi">'
        . '<div class="bacheca-colore-area" tabindex="0" role="slider" aria-label="Tinta e luminosità: frecce a destra e a sinistra per la tinta, in su e in giù per chiaro e scuro"'
        . ' aria-valuemin="0" aria-valuemax="359" aria-valuenow="0">'
        . '<canvas width="320" height="160"></canvas><span class="bacheca-colore-cursore"></span></div>'
        . '<p class="bacheca-colore-legenda" aria-hidden="true"><span>tutte le tinte</span><span>in alto più chiaro, in basso più scuro</span></p>'
        . '<label class="bacheca-colore-intensita">Intensità <input type="range" min="0" max="100" value="70"></label>'
        . '<label class="bacheca-colore-codice">Colore scelto <input type="color" name="color" value="' . strtolower($scelto) . '"></label>'
        . '</div><div class="bacheca-colore-anteprima" aria-hidden="true"><p class="bacheca-colore-anteprima-etichetta">Anteprima</p>'
        . '<div class="bacheca-colore-anteprima-riquadro" style="--cat:' . $scelto . ';--cat-soft:' . $col['soft'] . ';--cat-titolo:' . $col['titolo'] . '">'
        . '<span class="bacheca-colore-anteprima-titolo">' . htmlspecialchars($nome !== '' ? $nome : 'Nuova categoria') . '</span>'
        . '<span class="bacheca-colore-anteprima-testo">Il titolo prende la tinta scelta, scurita quanto serve a leggersi.</span>'
        . '</div></div></div></details>'
        . '</fieldset>'
        . CampoContato::html($p . '-descrizione', 'Descrizione (facoltativa)', 'description',
            Bacheca::MAX_DESCRIZIONE_CATEGORIA, (string) ($c['description'] ?? ''), 2);
};
?>
<section class="card" id="bacheca">
    <h2>Bacheca</h2>
    <p class="card-meta">
        Le categorie della <a href="/bacheca?corso=<?= $courseId ?>">bacheca del corso</a>: gli iscritti le vedono come
        riquadri colorati, in quest'ordine. Il tutor sceglie fra queste quando pubblica una risorsa. Una categoria
        con delle risorse non si può eliminare.
    </p>

    <?php if ($bacheca === []): ?>
        <p class="empty-state-small">Nessuna categoria: finché non ne aggiungi una, la bacheca del corso è vuota.</p>
    <?php else: ?>
        <ul class="bacheca-cat-admin" role="list">
            <?php foreach ($bacheca as $i => $c): ?>
                <?php
                $id = (int) $c['id'];
                $col = Bacheca::colore((string) $c['color']);
                ?>
                <li style="--cat:<?= $col['primary'] ?>;--cat-soft:<?= $col['soft'] ?>;--cat-titolo:<?= $col['titolo'] ?>">
                    <details class="qa-voce">
                        <summary>
                            <span class="bacheca-campione" aria-hidden="true"></span>
                            <span><?= htmlspecialchars((string) $c['name']) ?>
                                <span class="bacheca-cat-admin-conto">· <?= htmlspecialchars(Bacheca::conta((int) $c['pubblicate'])) ?></span></span>
                        </summary>
                        <div class="qa-corpo">
                            <form action="/admin/bacheca/categorie/<?= $id ?>" method="post" class="form">
                                <?= Csrf::field() ?>
                                <?= $campiCategoria('cat-' . $id, $c) ?>
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">Salva</button>
                                    <button type="reset" class="btn btn-secondary">Annulla</button>
                                </div>
                            </form>
                            <div class="bacheca-cat-admin-azioni">
                                <?php if ($i > 0): ?>
                                    <form action="/admin/bacheca/categorie/<?= $id ?>/sposta" method="post">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="direction" value="up">
                                        <button type="submit" class="link-btn comando-con-icona"><?= Icona::svg('arrow-up') ?>Sposta su</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($i < $ultima): ?>
                                    <form action="/admin/bacheca/categorie/<?= $id ?>/sposta" method="post">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="direction" value="down">
                                        <button type="submit" class="link-btn comando-con-icona"><?= Icona::svg('arrow-down') ?>Sposta giù</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ((int) $c['tutte'] === 0): ?>
                                    <form action="/admin/bacheca/categorie/<?= $id ?>/elimina" method="post"
                                          onsubmit="return confirm('Eliminare la categoria?');">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="link-btn link-btn-danger comando-con-icona"><?= Icona::svg('trash3') ?>Elimina</button>
                                    </form>
                                <?php else: ?>
                                    <span class="card-meta">Ha delle risorse (anche proposte o tolte): non si può eliminare.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </details>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h3 class="bacheca-cat-admin-nuova">Nuova categoria</h3>
    <form action="/admin/courses/<?= $courseId ?>/bacheca/categorie" method="post" class="form">
        <?= Csrf::field() ?>
        <?= $campiCategoria('cat-nuova', []) ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-secondary">Aggiungi la categoria</button>
            <button type="reset" class="btn btn-secondary">Annulla</button>
        </div>
    </form>
</section>
<?php /* Il contatore della descrizione (17/10): lo script caricato dai dati
         del corso, piu' su, gira prima che questo riquadro esista e non lo
         vedeva. Come il benvenuto, il riquadro lo porta con se'; caricato
         due volte, ogni campo si collega una volta sola. */ ?>
<script src="/assets/js/quiz-open-count.js"></script>
<script src="/assets/js/bacheca-colore.js"></script>
