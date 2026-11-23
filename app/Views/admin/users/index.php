<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Core\Csrf;
use App\Core\FiltroUtenti;
use App\Core\Invites;
use App\Core\Ordinamento;
use App\Core\Icona;
use App\Models\UserModel;

/** @var array $users */
/** @var bool $canManageAll */
/** @var bool $canExportXlsx */
/** @var int $invitiInAttesa */
/** @var ?string $ultimoInvio */
/** @var int $totale */
/** @var FiltroUtenti $filtro */
/** @var array{gruppi: list<string>, tutor: list<string>} $vociFiltro */

$ordine = Ordinamento::daRichiesta([
    'nome' => ['full_name', Ordinamento::TESTO, 'email'],
    'email' => ['email', Ordinamento::TESTO],
    'ruolo' => ['role', Ordinamento::TESTO],
    'gruppi' => ['gruppi', Ordinamento::TESTO],
    'tutor' => ['tutor_riferimento', Ordinamento::TESTO],
    // Lo stato e' 0 o 1: ordinando per numero, un clic raggruppa i
    // disattivati in fondo e il clic opposto li porta in cima.
    'stato' => ['is_active', Ordinamento::NUMERO],
]);
$users = $ordine->applica($users);

// Il filtro per gruppo e tutor (20/11, Alessandro). L'ordine scelto resta
// applicando e togliendo il filtro; il filtro resta ordinando (`Ordinamento`
// rimette gli altri parametri dell'indirizzo).
$ordineAttuale = array_filter([
    'ordina' => is_string($_GET['ordina'] ?? null) ? $_GET['ordina'] : null,
    'verso' => is_string($_GET['verso'] ?? null) ? $_GET['verso'] : null,
], static fn (?string $v): bool => $v !== null && $v !== '');
$attivi = $filtro->attivi();
$conFiltro = $filtro->parametri() === [] ? '' : '?' . http_build_query($filtro->parametri());
$quantiScaricati = count($users) === 1 ? '1 utente' : count($users) . ' utenti';
?>
<div class="page-header">
    <h1>Utenti</h1>
    <p class="page-subtitle">
        <?= $canManageAll
            ? 'Creazione utenti, ruoli, tutor di riferimento e password.'
            : 'Gli studenti: creazione, dati, gruppi e password.' ?>
    </p>
    <div class="page-actions">
        <a href="/admin/users/create" class="btn btn-primary btn-con-icona"><?= Icona::svg('plus-lg') ?>Nuovo utente</a>

        <a href="/admin/users/importa" class="btn btn-secondary btn-con-icona"><?= Icona::svg('upload') ?>Importa da file</a>

        <?php if ($canManageAll): ?>
            <?php /* `details`/`summary`: la tendina si apre e si chiude da sola,
                     senza JavaScript, e si usa da tastiera come qualunque
                     pulsante. Lo script aggiunge solo la chiusura con Esc e
                     con un clic fuori — toglie, non abilita (Sezione 4 del
                     promemoria). */ ?>
            <details class="dropdown">
                <summary class="btn btn-secondary btn-con-icona"><?= Icona::svg('download') ?>Scarica dati</summary>
                <div class="dropdown-menu">
                    <?php /* Con il filtro attivo si scaricano solo gli utenti filtrati, e la voce lo dice. */ ?>
                    <a href="/admin/users/csv<?= htmlspecialchars($conFiltro) ?>">CSV<?= $attivi > 0 ? ', ' . $quantiScaricati : '' ?></a>
                    <?php if ($canExportXlsx): ?>
                        <a href="/admin/users/xlsx<?= htmlspecialchars($conFiltro) ?>">XLSX<?= $attivi > 0 ? ', ' . $quantiScaricati : '' ?></a>
                    <?php endif; ?>
                </div>
            </details>
        <?php endif; ?>

        <?php /* «Filtra» (20/11, Alessandro): una tendina come «Scarica dati»,
                 con dentro un modulo GET. Il numero fra parentesi dice quanti
                 criteri sono attivi. */ ?>
        <details class="dropdown">
            <summary class="btn btn-secondary btn-con-icona"><?= Icona::svg('funnel') ?>Filtra<?= $attivi > 0 ? ' (' . $attivi . ')' : '' ?></summary>
            <form action="/admin/users" method="get" class="dropdown-menu filtro-utenti">
                <?php foreach ($ordineAttuale as $nome => $valore): ?>
                    <input type="hidden" name="<?= htmlspecialchars($nome) ?>" value="<?= htmlspecialchars($valore) ?>">
                <?php endforeach; ?>
                <?php foreach (['gruppo' => ['Gruppo', $vociFiltro['gruppi'], $filtro->gruppo()], 'tutor' => ['Tutor', $vociFiltro['tutor'], $filtro->tutor()]] as $campo => [$etichetta, $voci, $scelto]): ?>
                    <label for="filtro-<?= $campo ?>"><?= $etichetta ?></label>
                    <select id="filtro-<?= $campo ?>" name="<?= $campo ?>">
                        <option value="">Tutti</option>
                        <?php foreach ($voci as $voce): ?>
                            <option value="<?= htmlspecialchars($voce) ?>" <?= $voce === $scelto ? 'selected' : '' ?>><?= htmlspecialchars($voce) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-primary">Applica</button>
            </form>
        </details>
    </div>
</div>

<?php /* Gli inviti ancora da mandare.
         NON E' UN DETTAGLIO DECORATIVO. Chi e' stato importato non ha una
         password finche' l'invito non parte: se il cron non e' mai stato
         creato, o si e' fermato, queste persone non entrano e nessuno se ne
         accorge — il difetto gia' noto del rilascio dei moduli, ma qui le
         conseguenze sono peggiori. Il numero non scende, e si vede. */ ?>
<?php if ($invitiInAttesa > 0): ?>
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
            <button type="submit" class="link-btn comando-con-icona"><?= Icona::svg('send') ?>Manda adesso il prossimo scaglione</button>
        </form>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../_flash.php'; ?>

<?php if ($attivi > 0): ?>
    <?php /* Il filtro attivo si vede sempre, con il comando per toglierlo:
             un filtro dimenticato sembra un utente sparito. */ ?>
    <p class="filtro-attivo">
        <span><?= count($users) === 1 ? '1 utente' : count($users) . ' utenti' ?> su <?= (int) $totale ?><?php
            if ($filtro->gruppo() !== null): ?> · Gruppo: <?= htmlspecialchars($filtro->gruppo()) ?><?php endif;
            if ($filtro->tutor() !== null): ?> · Tutor: <?= htmlspecialchars($filtro->tutor()) ?><?php endif; ?></span>
        <a href="/admin/users<?= $ordineAttuale === [] ? '' : '?' . htmlspecialchars(http_build_query($ordineAttuale)) ?>" class="link-btn comando-con-icona"><?= Icona::svg('x-circle') ?>Togli filtro</a>
    </p>
<?php endif; ?>

<?php if ($users === []): ?>
    <p class="empty-state"><?= $attivi > 0 ? 'Nessun utente con questo filtro.' : 'Nessun utente da mostrare.' ?></p>
<?php else: ?>
    <?php /* Le righe si selezionano con le caselle, e i comandi stanno nella
             barra in fondo (20/11, Alessandro, variante B del mockup): la
             colonna «Modifica / Elimina» non c'e' piu', e lo spazio va alla
             colonna Gruppi. Il nome apre la modifica. Tutto e' un modulo GET
             verso `/admin/users/selezione`: senza JavaScript la barra resta
             ferma in fondo alla tabella con tutti i comandi; lo script
             (`utenti-selezione.js`) la fa comparire in fondo allo schermo
             solo quando c'e' una riga selezionata, e la adatta al numero. */ ?>
    <form action="/admin/users/selezione" method="get" class="utenti-selezione" data-selezione>
    <?php /* Sette colonne su un telefono non ci stanno: sotto i 50 rem di
             spazio la tabella diventa un elenco di schede, e ogni valore si
             porta davanti l'etichetta della sua colonna. I `role` espliciti
             servono perché cambiando il `display` la tabella perderebbe le
             proprie semantiche. Nelle schede la casella sta in alto a
             destra, e il nome resta una riga con la sua etichetta
             (`schede-senza-titolo`), come nel mockup. */ ?>
    <?php /* Nel riquadro bianco, come i report (30/10, Alessandro): ogni elenco sta in un riquadro. */ ?>
    <div class="tabella-schede schede-senza-titolo card">
        <table class="data-table" role="table">
            <thead role="rowgroup">
            <tr role="row">
                <th scope="col" role="columnheader" class="sel-col">
                    <?php /* Solo con lo script, che lo fa funzionare: senza resta nascosto. */ ?>
                    <label class="sel-casella" data-seleziona-tutti-etichetta hidden><input type="checkbox" aria-label="Seleziona tutti" data-seleziona-tutti></label>
                </th>
                <?= $ordine->th('Nome', 'nome') ?>
                <?= $ordine->th('Email', 'email') ?>
                <?= $ordine->th('Ruolo', 'ruolo') ?>
                <?= $ordine->th('Gruppi', 'gruppi') ?>
                <?= $ordine->th('Tutor', 'tutor') ?>
                <?= $ordine->th('Stato', 'stato') ?>
            </tr>
            </thead>
            <tbody role="rowgroup">
            <?php foreach ($users as $user): ?>
                <?php
                // Una riga per gruppo, con il suo tutor sulla stessa riga
                // della colonna dopo (20/11, Alessandro): due gruppi con due
                // tutor non si confondono. Un gruppo senza tutor ha «—».
                $coppie = UserModel::coppieGruppi($user['gruppi_tutor'] ?? null);
                ?>
                <tr role="row">
                    <td role="cell" class="sel-col">
                        <?php /* L'etichetta e' il bersaglio da toccare, piu' grande del quadratino. */ ?>
                        <label class="sel-casella"><input type="checkbox" name="ids[]" value="<?= (int) $user['id'] ?>"
                               aria-label="Seleziona <?= htmlspecialchars((string) $user['full_name']) ?>"></label>
                    </td>
                    <td role="cell" data-label="Nome"><a href="/admin/users/<?= (int) $user['id'] ?>/edit"><?= htmlspecialchars((string) $user['full_name']) ?></a></td>
                    <td role="cell" data-label="Email"><?= htmlspecialchars((string) $user['email']) ?></td>
                    <td role="cell" data-label="Ruolo"><?= htmlspecialchars(Auth::roleLabel($user['role'] ?? 'assistente')) ?></td>
                    <?php if ($coppie === []): ?>
                        <td role="cell" data-label="Gruppi">—</td>
                        <td role="cell" data-label="Tutor"><?= htmlspecialchars((string) ($user['tutor_riferimento'] ?? '—')) ?></td>
                    <?php else: ?>
                        <td role="cell" data-label="Gruppi" class="celle-appaiate">
                            <?php foreach ($coppie as $coppia): ?>
                                <span class="cell-line"><?= htmlspecialchars($coppia['gruppo']) ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td role="cell" data-label="Tutor" class="celle-appaiate">
                            <?php foreach ($coppie as $coppia): ?>
                                <span class="cell-line"><?= htmlspecialchars($coppia['tutor'] ?? '—') ?></span>
                            <?php endforeach; ?>
                        </td>
                    <?php endif; ?>
                    <td role="cell" data-label="Stato">
                        <?php if ((int) $user['is_active'] === 1): ?>
                            <span class="badge badge-success">Attivo</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Disattivato</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="barra-selezione" role="region" aria-label="Utenti selezionati" data-barra>
        <div class="barra-selezione-riga">
            <span class="barra-selezione-conto" aria-live="polite" data-conto>Seleziona uno o più utenti, poi scegli che cosa fare.</span>
            <button type="submit" name="azione" value="modifica" class="link-btn comando-con-icona" data-modifica><?= Icona::svg('pencil') ?>Modifica</button>
            <button type="submit" name="azione" value="elimina" class="link-btn link-btn-danger comando-con-icona" data-elimina><?= Icona::svg('trash3') ?><span data-elimina-testo>Elimina</span></button>
        </div>
        <button type="reset" class="btn btn-secondary">Annulla</button>
    </div>
    </form>
    <script src="/assets/js/utenti-selezione.js"></script>
<?php endif; ?>

<?php if ($canManageAll): ?>
    <script src="/assets/js/dropdown.js"></script>
<?php endif; ?>
