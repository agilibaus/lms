<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Invites;
use App\Core\UserImport;

/**
 * @var array<int, array<string, mixed>> $gruppi
 * @var int $inAttesa
 */
?>
<div class="page-header">
    <a href="/admin/users" class="back-link"><span class="back-link-testo">Utenti</span></a>
    <h1>Importa utenti</h1>
    <p class="page-subtitle">
        Un file CSV con un elenco di persone. Vengono create come studenti, e la password
        gliela manda Pistacchio.
    </p>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<?php if ($inAttesa > 0): ?>
    <div class="alert alert-info">
        Ci sono <strong><?= (int) $inAttesa ?></strong> inviti ancora da mandare.
        Partono da soli, <?= Invites::PER_SCAGLIONE ?> alla volta.
    </div>
<?php endif; ?>

<section class="card">
    <h2>Com'è fatto il file</h2>
    <p class="card-meta">
        La prima riga contiene i nomi delle colonne. L'ordine non conta, le maiuscole
        nemmeno. Separatore virgola o punto e virgola: li riconosce da sé, come riconosce
        i file salvati da Excel su Windows.
    </p>

    <table class="data-table">
        <thead>
        <tr>
            <th scope="col">Colonna</th>
            <th scope="col">Serve?</th>
            <th scope="col">Note</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td data-label="Colonna"><code>email</code></td>
            <td data-label="Serve?">Sì</td>
            <td data-label="Note">È l'account: lì arriva la password. Si accetta anche «e-mail».</td>
        </tr>
        <tr>
            <td data-label="Colonna"><code>nome</code></td>
            <td data-label="Serve?">Sì</td>
            <td data-label="Note">—</td>
        </tr>
        <tr>
            <td data-label="Colonna"><code>cognome</code></td>
            <td data-label="Serve?">Sì</td>
            <td data-label="Note">In una colonna separata dal nome.</td>
        </tr>
        <tr>
            <td data-label="Colonna"><code>gruppo</code></td>
            <td data-label="Serve?">No</td>
            <td data-label="Note">
                Il nome esatto di un gruppo che esiste già. Chi entra in un gruppo viene
                iscritto anche ai suoi corsi.
            </td>
        </tr>
        </tbody>
    </table>

    <p class="form-hint">
        Massimo <?= UserImport::MAX_RIGHE ?> righe per file. Le righe con un errore vengono
        mostrate prima di importare, e saltate: le altre passano lo stesso.
    </p>

    <?php if ($gruppi !== []): ?>
        <p class="form-hint">
            Gruppi che puoi nominare nel file:
            <?= htmlspecialchars(implode(' · ', array_map(
                static fn (array $g): string => (string) $g['name'],
                $gruppi
            ))) ?>
        </p>
    <?php else: ?>
        <p class="form-hint">
            Non c'è ancora nessun gruppo: la colonna «gruppo» va lasciata vuota, oppure
            <a href="/admin/groups/create">creane uno</a> prima di importare.
        </p>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Il file</h2>

    <form action="/admin/users/importa/anteprima" method="post" enctype="multipart/form-data" class="form">
        <?= Csrf::field() ?>

        <label for="file">File CSV</label>
        <input type="file" id="file" name="file" accept=".csv,text/csv,text/plain" required>
        <p class="form-hint">
            Da Excel: «Salva con nome» e scegli CSV. Nessun utente viene creato adesso —
            prima vedi un'anteprima di quello che succederebbe.
        </p>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Leggi il file</button>
            <a href="/admin/users" class="btn btn-secondary">Annulla</a>
        </div>
    </form>
</section>

<section class="card">
    <h2>Le password</h2>
    <p class="card-meta">
        Nessuno sceglie le password, nemmeno tu: le genera Pistacchio nel momento in cui
        manda l'email, e chi entra deve cambiarla subito. Gli inviti non partono tutti
        insieme ma a scaglioni di <?= Invites::PER_SCAGLIONE ?>, ogni quarto d'ora, perché
        duecento email identiche in mezz'ora sono il modo più rapido di finire nello spam di
        tutti. Fino a quando l'invito non parte, quella persona non ha una password e non può
        entrare: il conteggio in fondo alla pagina Utenti dice quanti ne mancano.
    </p>
</section>
