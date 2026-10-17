<?php

declare(strict_types=1);

use App\Core\Bacheca;
use App\Core\CampoContato;
use App\Core\Csrf;

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

/** I campi di una categoria: gli stessi per correggere e per aggiungere. */
$campiCategoria = static function (string $p, array $c): string {
    $colore = (string) ($c['color'] ?? 'verde');
    $opzioni = '';

    foreach (Bacheca::COLORI as $chiave => $nome) {
        $opzioni .= '<option value="' . $chiave . '"' . ($chiave === $colore ? ' selected' : '') . '>' . $nome . '</option>';
    }

    return '<label for="' . $p . '-nome">Nome</label>'
        . '<input type="text" id="' . $p . '-nome" name="name" required maxlength="' . Bacheca::MAX_NOME_CATEGORIA . '" value="'
        . htmlspecialchars((string) ($c['name'] ?? ''), ENT_QUOTES) . '">'
        . '<label for="' . $p . '-colore">Colore</label>'
        . '<select id="' . $p . '-colore" name="color">' . $opzioni . '</select>'
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
                <li style="--cat:<?= $col['primary'] ?>;--cat-soft:<?= $col['soft'] ?>">
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
                                        <button type="submit" class="link-btn">Sposta su</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($i < $ultima): ?>
                                    <form action="/admin/bacheca/categorie/<?= $id ?>/sposta" method="post">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="direction" value="down">
                                        <button type="submit" class="link-btn">Sposta giù</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ((int) $c['tutte'] === 0): ?>
                                    <form action="/admin/bacheca/categorie/<?= $id ?>/elimina" method="post"
                                          onsubmit="return confirm('Eliminare la categoria?');">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="link-btn link-btn-danger">Elimina</button>
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
