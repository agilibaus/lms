<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Core\Csrf;
use App\Core\Invites;
use App\Core\Ordinamento;

/** @var array $users */
/** @var bool $canManageAll */
/** @var bool $canExportXlsx */
/** @var int $invitiInAttesa */
/** @var ?string $ultimoInvio */

$ordine = Ordinamento::daRichiesta([
    'nome' => ['full_name', Ordinamento::TESTO, 'email'],
    'email' => ['email', Ordinamento::TESTO],
    'ruolo' => ['role', Ordinamento::TESTO],
    'tutor' => ['tutor_riferimento', Ordinamento::TESTO],
    // Lo stato e' 0 o 1: ordinando per numero, un clic raggruppa i
    // disattivati in fondo e il clic opposto li porta in cima.
    'stato' => ['is_active', Ordinamento::NUMERO],
]);
$users = $ordine->applica($users);
?>
<div class="page-header">
    <h1>Utenti</h1>
    <p class="page-subtitle">
        <?= $canManageAll
            ? 'Creazione utenti, ruoli, tutor di riferimento e password.'
            : 'Gli assistenti assegnati a te.' ?>
    </p>
    <div class="page-actions">
        <a href="/admin/users/create" class="btn btn-primary">+ Nuovo utente</a>

        <?php if ($canManageAll): ?>
            <a href="/admin/users/importa" class="btn btn-secondary">Importa da file</a>
        <?php endif; ?>

        <?php if ($canManageAll): ?>
            <?php /* `details`/`summary`: la tendina si apre e si chiude da sola,
                     senza JavaScript, e si usa da tastiera come qualunque
                     pulsante. Lo script aggiunge solo la chiusura con Esc e
                     con un clic fuori — toglie, non abilita (Sezione 4 del
                     promemoria). */ ?>
            <details class="dropdown">
                <summary class="btn btn-secondary">Scarica dati</summary>
                <div class="dropdown-menu">
                    <a href="/admin/users/csv">CSV</a>
                    <?php if ($canExportXlsx): ?>
                        <a href="/admin/users/xlsx">XLSX</a>
                    <?php endif; ?>
                </div>
            </details>
        <?php endif; ?>
    </div>
</div>

<?php /* Gli inviti ancora da mandare.
         NON E' UN DETTAGLIO DECORATIVO. Chi e' stato importato non ha una
         password finche' l'invito non parte: se il cron non e' mai stato
         creato, o si e' fermato, queste persone non entrano e nessuno se ne
         accorge — il difetto gia' noto del rilascio dei moduli, ma qui le
         conseguenze sono peggiori. Il numero non scende, e si vede. */ ?>
<?php if ($canManageAll && $invitiInAttesa > 0): ?>
    <div class="alert alert-warning">
        <strong><?= (int) $invitiInAttesa ?></strong>
        <?= $invitiInAttesa === 1 ? 'invito è ancora da mandare' : 'inviti sono ancora da mandare' ?>:
        fino ad allora quelle persone non hanno una password e non possono entrare.
        Partono da soli a scaglioni di <?= Invites::PER_SCAGLIONE ?>.
        <?php if ($ultimoInvio !== null): ?>
            Ultimo scaglione:
            <?= htmlspecialchars(date('d/m/Y H:i', (int) strtotime($ultimoInvio))) ?>.
        <?php else: ?>
            <strong>Non è ancora partito nessuno scaglione</strong>: controlla che la riga di
            cron per <code>bin/invita-utenti</code> esista.
        <?php endif; ?>
        <form action="/admin/users/inviti/manda" method="post">
            <?= Csrf::field() ?>
            <button type="submit" class="link-btn">Manda adesso il prossimo scaglione</button>
        </form>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../_flash.php'; ?>

<?php if ($users === []): ?>
    <p class="empty-state">Nessun utente da mostrare.</p>
<?php else: ?>
    <?php /* Sei colonne su un telefono non ci stanno: sotto i 50 rem di
             spazio la tabella diventa un elenco di schede, e ogni valore si
             porta davanti l'etichetta della sua colonna. I `role` espliciti
             servono perché cambiando il `display` la tabella perderebbe le
             proprie semantiche. */ ?>
    <div class="tabella-schede">
        <table class="data-table" role="table">
            <thead role="rowgroup">
            <tr role="row">
                <?= $ordine->th('Nome', 'nome') ?>
                <?= $ordine->th('Email', 'email') ?>
                <?= $ordine->th('Ruolo', 'ruolo') ?>
                <?= $ordine->th('Tutor', 'tutor') ?>
                <?= $ordine->th('Stato', 'stato') ?>
                <th scope="col" role="columnheader"><span class="sr-only">Azioni</span></th>
            </tr>
            </thead>
            <tbody role="rowgroup">
            <?php foreach ($users as $user): ?>
                <tr role="row">
                    <td role="cell" data-label="Nome"><?= htmlspecialchars((string) $user['full_name']) ?></td>
                    <td role="cell" data-label="Email"><?= htmlspecialchars((string) $user['email']) ?></td>
                    <td role="cell" data-label="Ruolo"><?= htmlspecialchars(Auth::roleLabel($user['role'] ?? 'assistente')) ?></td>
                    <td role="cell" data-label="Tutor"><?= htmlspecialchars((string) ($user['tutor_riferimento'] ?? '—')) ?></td>
                    <td role="cell" data-label="Stato">
                        <?php if ((int) $user['is_active'] === 1): ?>
                            <span class="badge badge-success">attivo</span>
                        <?php else: ?>
                            <span class="badge badge-danger">disattivato</span>
                        <?php endif; ?>
                    </td>
                    <td role="cell" class="row-actions">
                        <a href="/admin/users/<?= (int) $user['id'] ?>/edit">Modifica</a>
                        <form action="/admin/users/<?= (int) $user['id'] ?>/delete" method="post"
                              onsubmit="return confirm('Eliminare questo utente? Iscrizioni, progressi, tentativi dei questionari e certificati verranno rimossi. In alternativa puoi disattivarlo.');">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-btn link-btn-danger">Elimina</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($canManageAll): ?>
    <script src="/assets/js/dropdown.js"></script>
<?php endif; ?>
