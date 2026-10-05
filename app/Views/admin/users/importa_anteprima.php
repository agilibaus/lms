<?php

declare(strict_types=1);

use App\Core\Csrf;

/**
 * L'anteprima: che cosa succederebbe premendo «Importa».
 *
 * @var list<array<string, mixed>> $righe
 * @var list<array<string, mixed>> $nuovi
 * @var list<array<string, mixed>> $scartate
 * @var string $nomeFile
 */
?>
<div class="page-header">
    <a href="/admin/users/importa" class="back-link">← Importa utenti</a>
    <h1>Anteprima</h1>
    <p class="page-subtitle">
        <?= htmlspecialchars($nomeFile) ?> — <?= count($righe) ?> righe lette.
        <strong>Non è stato creato ancora niente.</strong>
    </p>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<div class="alert <?= $nuovi === [] ? 'alert-warning' : 'alert-success' ?>">
    <?php if ($nuovi === []): ?>
        Nessuna riga importabile: sotto c'è scritto perché, riga per riga.
    <?php else: ?>
        Verranno creati <strong><?= count($nuovi) ?></strong>
        <?= count($nuovi) === 1 ? 'utente' : 'utenti' ?><?php
        if ($scartate !== []): ?>, e <strong><?= count($scartate) ?></strong>
        <?= count($scartate) === 1 ? 'riga verrà saltata' : 'righe verranno saltate' ?><?php
        endif; ?>.
    <?php endif; ?>
</div>

<section class="card">
    <h2>Riga per riga</h2>

    <div class="tabella-schede">
        <table class="data-table" role="table">
            <thead role="rowgroup">
            <tr role="row">
                <th scope="col" role="columnheader">Riga</th>
                <th scope="col" role="columnheader">Email</th>
                <th scope="col" role="columnheader">Nome</th>
                <th scope="col" role="columnheader">Gruppo</th>
                <th scope="col" role="columnheader">Esito</th>
            </tr>
            </thead>
            <tbody role="rowgroup">
            <?php foreach ($righe as $riga): ?>
                <tr role="row" class="<?= $riga['errore'] !== null ? 'row-past' : '' ?>">
                    <td role="cell" data-label="Riga"><?= (int) $riga['numero'] ?></td>
                    <td role="cell" data-label="Email"><?= htmlspecialchars((string) $riga['email']) ?></td>
                    <td role="cell" data-label="Nome"><?= htmlspecialchars((string) $riga['nome']) ?></td>
                    <td role="cell" data-label="Gruppo"><?= htmlspecialchars((string) $riga['gruppo']) ?></td>
                    <td role="cell" data-label="Esito">
                        <?php if ($riga['errore'] === null): ?>
                            <span class="badge badge-success">da creare</span>
                        <?php else: ?>
                            <?php /* Il motivo scritto per esteso, non un'icona: chi
                                     guarda deve poter correggere il file, e per
                                     correggerlo deve sapere che cosa non andava. */ ?>
                            <span class="badge badge-muted">saltata</span>
                            <span class="cell-sub"><?= htmlspecialchars((string) $riga['errore']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($nuovi !== []): ?>
    <form action="/admin/users/importa" method="post" class="form">
        <?= Csrf::field() ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">
                Importa <?= count($nuovi) ?> <?= count($nuovi) === 1 ? 'utente' : 'utenti' ?>
            </button>
            <a href="/admin/users/importa" class="btn btn-secondary">Cambia file</a>
        </div>
    </form>
<?php else: ?>
    <p><a href="/admin/users/importa" class="btn btn-secondary">Torna indietro</a></p>
<?php endif; ?>
