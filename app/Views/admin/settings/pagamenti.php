<?php

declare(strict_types=1);

use App\Core\CorsiAPagamento;
use App\Core\Csrf;

/**
 * Impostazioni › Pagamenti (06/11, variante A dei due mockup): l'interruttore
 * generale dei corsi a pagamento, il riquadro «Stripe» (chiave degli avvisi,
 * indirizzo da copiare su Stripe, modalita' di prova) e sotto i pagamenti
 * ricevuti, filtrabili per corso.
 *
 * @var bool $attivi
 * @var bool $prova
 * @var bool $haChiave
 * @var string $indirizzoAvvisi
 * @var array|null $lastUpdate
 * @var list<array<string, mixed>> $pagamenti
 * @var list<array{id: int, title: string}> $corsiPagati
 * @var int $corsoScelto
 */
?>
<div class="page-header">
    <a href="/admin/settings" class="back-link"><span class="back-link-testo">Impostazioni</span></a>
    <h1>Pagamenti</h1>
    <p class="page-subtitle">I corsi a pagamento: lo studente paga su Stripe e Pistacchio LMS lo iscrive quando il pagamento arriva.</p>
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
                <span class="ruoli-interruttore-cosa" id="pagamenti-attivi-cosa">Accesa, fra i modi di iscrizione di un corso compare «A pagamento», e i corsi a pagamento pubblicati compaiono nel catalogo con il prezzo. Spenta, i corsi a pagamento non compaiono nel catalogo e non si comprano da Pistacchio LMS; un pagamento che arriva lo stesso iscrive comunque chi ha pagato.</span>
            </span>
        </label>
    </section>

    <section class="card">
        <h2>Stripe</h2>

        <label for="stripe-chiave">Chiave degli avvisi</label>
        <input type="password" id="stripe-chiave" name="<?= CorsiAPagamento::KEY_SEGRETO ?>" autocomplete="off"
               aria-describedby="stripe-chiave-aiuto"
               placeholder="<?= $haChiave ? 'impostata — scrivi qui per sostituirla' : 'nessuna chiave impostata' ?>">
        <p class="form-hint" id="stripe-chiave-aiuto">
            Comincia con «whsec_». Si copia da Stripe, nella pagina dell’endpoint degli avvisi. Non viene mai
            rimandata al browser: se lasci il campo vuoto resta quella di prima.
        </p>
        <?php if ($haChiave): ?>
            <label class="checkbox-label">
                <input type="checkbox" name="cancella_chiave" value="1">
                <span>Cancella la chiave salvata (gli avvisi di Stripe verranno rifiutati, e nessuno sarà iscritto dopo aver pagato)</span>
            </label>
        <?php endif; ?>

        <label for="stripe-indirizzo">Indirizzo degli avvisi</label>
        <input type="text" id="stripe-indirizzo" value="<?= htmlspecialchars($indirizzoAvvisi) ?>" readonly
               aria-describedby="stripe-indirizzo-aiuto">
        <p class="form-hint stripe-eventi" id="stripe-indirizzo-aiuto">
            Da incollare su Stripe come indirizzo dell’endpoint, con gli eventi «checkout.session.completed» e
            «checkout.session.async_payment_succeeded».
        </p>

        <input type="hidden" name="<?= CorsiAPagamento::KEY_PROVA ?>" value="0">
        <label class="checkbox-label ruoli-interruttore">
            <input type="checkbox" name="<?= CorsiAPagamento::KEY_PROVA ?>" value="1" id="pagamenti-prova"
                   aria-describedby="pagamenti-prova-cosa" <?= $prova ? 'checked' : '' ?>>
            <span>
                <span class="ruoli-interruttore-nome">Modalità di prova</span>
                <span class="ruoli-interruttore-cosa" id="pagamenti-prova-cosa">Accesa, Pistacchio LMS accetta solo i pagamenti di prova di Stripe (link «test_» e carte di prova). Spenta, solo i pagamenti veri.</span>
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

<section class="card pagamenti-ricevuti">
    <h2>Pagamenti ricevuti</h2>

    <?php if ($corsiPagati !== []): ?>
        <?php /* Il filtro per corso (06/11, Alessandro): un modulo GET, funziona
                 senza JavaScript. */ ?>
        <form method="get" action="/admin/settings/pagamenti" class="form form-inline">
            <select name="corso" aria-label="Corso">
                <option value="0">Tutti i corsi</option>
                <?php foreach ($corsiPagati as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $corsoScelto ? 'selected' : '' ?>><?= htmlspecialchars((string) $c['title']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary">Filtra</button>
        </form>
    <?php endif; ?>

    <?php if ($pagamenti === []): ?>
        <p class="empty-state-small"><?= $corsoScelto > 0 ? 'Nessun pagamento per questo corso.' : 'Nessun pagamento ricevuto.' ?></p>
    <?php else: ?>
        <div class="tabella-schede">
        <table class="data-table" role="table">
            <thead role="rowgroup">
            <tr role="row">
                <th scope="col" role="columnheader">Data</th>
                <th scope="col" role="columnheader">Studente</th>
                <th scope="col" role="columnheader">Corso</th>
                <th scope="col" role="columnheader">Importo</th>
                <th scope="col" role="columnheader">Esito</th>
            </tr>
            </thead>
            <tbody role="rowgroup">
            <?php foreach ($pagamenti as $p): ?>
                <tr role="row">
                    <td role="cell" data-label="Data"><?= htmlspecialchars(date('d/m/Y H:i', (int) strtotime((string) $p['received_at']))) ?></td>
                    <td role="cell" data-label="Studente">
                        <?= $p['full_name'] !== null ? htmlspecialchars((string) $p['full_name']) : '—' ?>
                        <?php $email = $p['user_email'] ?? $p['email']; ?>
                        <?php if ($email !== null && $email !== ''): ?>
                            <span class="cell-sub"><?= htmlspecialchars((string) $email) ?></span>
                        <?php endif; ?>
                    </td>
                    <td role="cell" data-label="Corso"><?= $p['course_title'] !== null ? htmlspecialchars((string) $p['course_title']) : '—' ?></td>
                    <td role="cell" data-label="Importo">
                        <?= htmlspecialchars(CorsiAPagamento::prezzo((int) $p['amount_total'])) ?>
                        <?php if ((int) $p['amount_total'] < (int) $p['amount_subtotal']): ?>
                            <span class="cell-sub">con sconto, su <?= htmlspecialchars(CorsiAPagamento::prezzo((int) $p['amount_subtotal'])) ?></span>
                        <?php endif; ?>
                        <?php if ((int) $p['livemode'] === 0): ?>
                            <span class="cell-sub">di prova</span>
                        <?php endif; ?>
                    </td>
                    <td role="cell" data-label="Esito">
                        <?php if ($p['esito'] === 'iscritto'): ?>
                            <span class="badge badge-success">iscrizione completata</span>
                        <?php else: ?>
                            <span class="badge badge-danger">iscrizione non riuscita</span>
                            <span class="cell-sub"><?= htmlspecialchars((string) $p['motivo']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>
