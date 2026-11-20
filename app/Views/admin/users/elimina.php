<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Core\Csrf;
use App\Core\Icona;

/**
 * La conferma prima di eliminare gli utenti selezionati nell'elenco (20/11,
 * Alessandro). Dice chi verra' eliminato, che cosa sparisce con lui, e chi
 * non si puo' eliminare e perche'. Una pagina e non una finestra del
 * browser: funziona anche senza JavaScript, e un elenco di nomi in una
 * finestra di conferma non si legge.
 */

/** @var list<array<string, mixed>> $eliminabili */
/** @var list<array<string, mixed>> $bloccati */

$quanti = count($eliminabili);
?>
<div class="page-header">
    <a href="/admin/users" class="back-link"><span class="back-link-testo">Utenti</span></a>
    <h1><?= $quanti === 0 ? 'Nessun utente da eliminare' : htmlspecialchars((string) ($pageTitle ?? '')) ?></h1>
</div>

<?php if ($quanti > 0): ?>
    <div class="card">
        <ul class="elenco-da-eliminare">
            <?php foreach ($eliminabili as $utente): ?>
                <li>
                    <?= htmlspecialchars((string) $utente['full_name']) ?>
                    <span class="cell-sub"><?= htmlspecialchars((string) $utente['email']) ?> · <?= htmlspecialchars(Auth::roleLabel($utente['role'] ?? null)) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="form-hint">
            <?= $quanti === 1 ? 'Con l\'utente vengono eliminati' : 'Con gli utenti vengono eliminati' ?>
            iscrizioni, progressi, tentativi dei questionari e certificati.
            Per conservarli, <?= $quanti === 1 ? 'disattivalo' : 'disattivali' ?> da «Modifica».
        </p>

        <form action="/admin/users/elimina" method="post" class="form-actions">
            <?= Csrf::field() ?>
            <?php foreach ($eliminabili as $utente): ?>
                <input type="hidden" name="ids[]" value="<?= (int) $utente['id'] ?>">
            <?php endforeach; ?>
            <button type="submit" class="link-btn link-btn-danger comando-con-icona"><?= Icona::svg('trash3') ?><?= $quanti === 1 ? 'Elimina l\'utente' : 'Elimina ' . $quanti . ' utenti' ?></button>
            <a href="/admin/users" class="btn btn-secondary">Annulla</a>
        </form>
    </div>
<?php endif; ?>

<?php if ($bloccati !== []): ?>
    <div class="card">
        <h2><?= count($bloccati) === 1 ? 'Non si può eliminare' : 'Non si possono eliminare' ?></h2>
        <ul class="elenco-da-eliminare">
            <?php foreach ($bloccati as $utente): ?>
                <li>
                    <?= htmlspecialchars((string) $utente['full_name']) ?>
                    <span class="cell-sub"><?= htmlspecialchars(mb_strtoupper(mb_substr((string) $utente['motivo'], 0, 1)) . mb_substr((string) $utente['motivo'], 1)) ?>.</span>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($quanti === 0): ?>
            <div class="form-actions">
                <a href="/admin/users" class="btn btn-secondary">Torna agli utenti</a>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
