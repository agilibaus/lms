<?php

declare(strict_types=1);

use App\Core\CorsiAPagamento;
use App\Core\Csrf;

/**
 * Impostazioni › Pagamenti (06/11, variante A del mockup): l'interruttore
 * generale dei corsi a pagamento, con la casella degli interruttori dei
 * Ruoli. Chiave degli avvisi di Stripe, modalita' di prova e pagamenti
 * ricevuti arrivano con la patch successiva.
 *
 * @var bool $attivi
 * @var array|null $lastUpdate
 */
?>
<div class="page-header">
    <a href="/admin/settings" class="back-link"><span class="back-link-testo">Impostazioni</span></a>
    <h1>Pagamenti</h1>
    <p class="page-subtitle">I corsi a pagamento, con un link di pagamento di Stripe per ciascuno.</p>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<form action="/admin/settings/pagamenti" method="post" class="form">
    <?= Csrf::field() ?>

    <section class="card">
        <h2>Corsi a pagamento</h2>

        <?php /* Il valore nascosto arriva anche a casella spenta: cosi' lo spento
                 si salva come '0', non come «non inviato». */ ?>
        <input type="hidden" name="<?= CorsiAPagamento::KEY ?>" value="0">
        <label class="checkbox-label ruoli-interruttore">
            <input type="checkbox" name="<?= CorsiAPagamento::KEY ?>" value="1" id="pagamenti-attivi"
                   aria-describedby="pagamenti-attivi-cosa" <?= $attivi ? 'checked' : '' ?>>
            <span>
                <span class="ruoli-interruttore-nome">Corsi a pagamento</span>
                <span class="ruoli-interruttore-cosa" id="pagamenti-attivi-cosa">Accesa, fra i modi di iscrizione di un corso compare «A pagamento». Spenta, i corsi a pagamento non compaiono nel catalogo e li iscrive solo l’admin, a mano.</span>
            </span>
        </label>
    </section>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Salva</button>
        <a href="/admin/settings" class="btn btn-secondary">Annulla</a>
    </div>
</form>

<?php if ($lastUpdate !== null): ?>
    <p class="form-hint">
        Ultima modifica:
        <?= htmlspecialchars(date('d/m/Y H:i', (int) strtotime((string) $lastUpdate['updated_at'])))
            . ($lastUpdate['full_name'] !== null
                ? ' — ' . htmlspecialchars((string) $lastUpdate['full_name'])
                : '') ?>
    </p>
<?php endif; ?>
