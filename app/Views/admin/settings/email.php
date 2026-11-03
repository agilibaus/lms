<?php

declare(strict_types=1);

use App\Core\CampoContato;
use App\Core\Csrf;
use App\Core\Mail\Modelli;

/**
 * Impostazioni › Messaggi email (12/10): i dati comuni a tutte le email, poi ogni
 * email con il suo testo. La pagina si costruisce da `Modelli::elenco()`:
 * un'email nuova e' una voce in piu' li', non un pezzo di pagina in piu' qui.
 *
 * @var array<string, string> $comuni
 * @var bool $mittenteDaFile
 * @var array<string, array{oggetto: string, testo: string}> $testi
 * @var array|null $lastUpdate
 * @var bool $logTransport
 * @var string|null $myEmail
 */

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$elenco = Modelli::elenco();
$perGruppo = [];

foreach ($elenco as $chiave => $voce) {
    $perGruppo[$voce['gruppo']][$chiave] = $voce;
}

// Una parte sola (30/10, Alessandro): `/admin/settings/email/voce/{voce}`,
// dove porta l'elenco al telefono. Dopo «Salva» e «Manda una prova a me» si
// torna qui (campo `pagina`).
$solo ??= null;
$campoPagina = $solo === null ? '' : '<input type="hidden" name="pagina" value="/admin/settings/email/voce/' . $e($solo) . '">';
$titoliComuni = ['mittente' => ['Organizzazione e mittente', 'Valgono per tutte le email.'], 'segnaposto' => ['Segnaposto comuni', 'Si possono usare in ogni email.']];
?>
<?php if ($solo !== null): ?>
<?php [$titoloSolo, $quandoSolo] = $titoliComuni[$solo] ?? [$elenco[$solo]['titolo'], $elenco[$solo]['quando']]; ?>
<div class="page-header">
    <a href="/admin/settings/email" class="back-link"><span class="back-link-testo">Messaggi email</span></a>
    <h1><?= $e($titoloSolo) ?></h1>
    <p class="page-subtitle"><?= $e($quandoSolo) ?></p>
</div>
<?php else: ?>
<div class="page-header">
    <a href="/admin/settings" class="back-link"><span class="back-link-testo">Impostazioni</span></a>
    <h1>Messaggi email</h1>
    <p class="page-subtitle">
        Chi firma le email della piattaforma e che cosa dicono. Un campo lasciato vuoto usa il testo
        predefinito, quello che si legge in grigio. Come partono si imposta in
        <a href="/admin/settings/posta">Server di posta elettronica</a>.
    </p>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../_flash.php'; ?>

<?php if ($logTransport): ?>
    <p class="empty-state-small">
        L’invio è in modalità “Registro”: i messaggi vengono scritti in <code>storage/mail</code>
        e non raggiungono nessuno. Si cambia nella pagina <a href="/admin/settings/posta">Server di posta elettronica</a>.
    </p>
<?php endif; ?>

<?php if ($solo === null): ?>
    <?php /* Al telefono (30/10, Alessandro, B) la pagina e' questo elenco: ogni
             voce apre la sua pagina. Al computer le parti, tutte aperte. */ ?>
    <nav class="email-elenco" aria-label="Le email">
        <h2>Per tutte le email</h2>
        <ul class="card email-voci">
            <?php foreach ($titoliComuni as $voceComune => [$titolo, $quando]): ?>
                <li><a href="/admin/settings/email/voce/<?= $e($voceComune) ?>">
                    <span class="email-voce-titolo"><?= $e($titolo) ?></span>
                    <span class="card-meta"><?= $e($quando) ?></span>
                </a></li>
            <?php endforeach; ?>
        </ul>
        <?php foreach (Modelli::GRUPPI as $gruppo => $nomeGruppo): ?>
            <?php if (empty($perGruppo[$gruppo])) {
                continue;
            } ?>
            <h2><?= $e($nomeGruppo) ?></h2>
            <ul class="card email-voci">
                <?php foreach ($perGruppo[$gruppo] as $chiave => $voce): ?>
                    <li><a href="/admin/settings/email/voce/<?= $e($chiave) ?>">
                        <span class="email-voce-titolo"><?= $e($voce['titolo']) ?></span>
                        <span class="card-meta"><?= $e($voce['quando']) ?></span>
                    </a></li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<div class="<?= $solo === null ? 'email-sezioni' : '' ?>">
<?php if ($solo === null || $solo === 'mittente'): ?>
<section class="card">
    <?php if ($solo === null): ?><h2>Organizzazione e mittente</h2><?php endif; ?>
    <p class="card-meta">Valgono per tutte le email.</p>

    <form action="/admin/settings/email" method="post" class="form">
        <?= $campoPagina ?>
        <?= Csrf::field() ?>

        <label for="<?= Modelli::ORGANIZZAZIONE ?>">Nome dell’organizzazione</label>
        <input type="text" id="<?= Modelli::ORGANIZZAZIONE ?>" name="<?= Modelli::ORGANIZZAZIONE ?>" maxlength="100"
               value="<?= $e($comuni[Modelli::ORGANIZZAZIONE]) ?>">
        <p class="form-hint">
            Prende il posto di <code>{organizzazione}</code>. Finché è vuoto vale «<?= $e(Modelli::PIATTAFORMA) ?>».
        </p>

        <label for="MAIL_FROM_NAME">Nome del mittente</label>
        <input type="text" id="MAIL_FROM_NAME" name="MAIL_FROM_NAME" maxlength="100"
               value="<?= $e($comuni['MAIL_FROM_NAME']) ?>"
               placeholder="<?= $e(Modelli::organizzazione()) ?>">
        <p class="form-hint">Quello che si legge nella casella di chi riceve. Vuoto, vale il nome dell’organizzazione.</p>

        <label for="MAIL_FROM_ADDRESS">Indirizzo del mittente</label>
        <input type="email" id="MAIL_FROM_ADDRESS" name="MAIL_FROM_ADDRESS"
               value="<?= $e($comuni['MAIL_FROM_ADDRESS']) ?>"
               placeholder="no-reply@tuodominio.it">
        <p class="form-hint">
            Usa un indirizzo del dominio da cui partono davvero le email, quello del server impostato in
            Server di posta elettronica: un mittente di un altro dominio finisce facilmente nello spam.
            <?php if ($mittenteDaFile): ?>Adesso vale quello del file <code>.env</code>.<?php endif; ?>
        </p>

        <label for="<?= Modelli::RISPONDI_A ?>">Rispondi a</label>
        <input type="email" id="<?= Modelli::RISPONDI_A ?>" name="<?= Modelli::RISPONDI_A ?>"
               value="<?= $e($comuni[Modelli::RISPONDI_A]) ?>"
               placeholder="segreteria@tuodominio.it">
        <p class="form-hint">
            Dove arrivano le risposte, se il mittente è un indirizzo che nessuno legge. Prende il posto di
            <code>{contatto}</code>; vuoto, vale l’indirizzo del mittente.
        </p>

        <?= CampoContato::html(Modelli::CHIUSURA, 'Chiusura', Modelli::CHIUSURA, Modelli::MAX_CHIUSURA,
            $comuni[Modelli::CHIUSURA], 5, false, Modelli::CHIUSURA_PREDEFINITA) ?>
        <p class="form-hint">Si aggiunge in fondo a ogni email. Può usare i segnaposto comuni.</p>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Salva</button>
            <button type="reset" class="btn btn-secondary">Annulla</button>
        </div>
    </form>
</section>
<?php endif; ?>

<?php if ($solo === null || $solo === 'segnaposto'): ?>
<section class="card">
    <?php if ($solo === null): ?><h2>Segnaposto comuni</h2><?php endif; ?>
    <p class="card-meta">
        Si possono usare in ogni email, nell’oggetto e nel testo. Quelli che non esistono restano scritti
        come sono, così un errore di battitura si vede nella prova invece di sparire.
    </p>
    <table class="data-table tabella-a-capo">
        <thead>
        <tr><th scope="col">Segnaposto</th><th scope="col">Diventa</th></tr>
        </thead>
        <tbody>
        <?php foreach (Modelli::segnapostoComuni() as $segnaposto => $spiegazione): ?>
            <tr>
                <td><code><?= $e($segnaposto) ?></code></td>
                <td><?= $e($spiegazione) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<?php foreach (Modelli::GRUPPI as $gruppo => $nomeGruppo): ?>
    <?php if (empty($perGruppo[$gruppo]) || ($solo !== null && !isset($perGruppo[$gruppo][$solo]))) {
        continue;
    } ?>
    <?php if ($solo === null): ?>
        <h2 id="<?= $e($gruppo) ?>"><?= $e($nomeGruppo) ?></h2>
    <?php endif; ?>

    <?php foreach ($perGruppo[$gruppo] as $chiave => $voce): ?>
        <?php if ($solo !== null && $solo !== $chiave) {
            continue;
        } ?>
        <section class="card" id="email-<?= $e($chiave) ?>">
            <?php if ($solo === null): ?>
                <h3><?= $e($voce['titolo']) ?></h3>
                <p class="card-meta"><?= $e($voce['quando']) ?></p>
            <?php endif; ?>

            <?php if ($voce['segnaposto'] !== []): ?>
                <table class="data-table tabella-a-capo">
                    <caption class="sr-only">Segnaposto di questa email</caption>
                    <thead>
                    <tr><th scope="col">Segnaposto</th><th scope="col">Diventa</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($voce['segnaposto'] as $segnaposto => $spiegazione): ?>
                        <tr>
                            <td><code><?= $e($segnaposto) ?></code></td>
                            <td>
                                <?= $e($spiegazione) ?>
                                <?php if (in_array($segnaposto, $voce['obbligatori'], true)): ?>
                                    — <strong>non può mancare nel testo</strong>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <form action="/admin/settings/email/testo" method="post" class="form">
                <?= Csrf::field() ?>
                <input type="hidden" name="chiave" value="<?= $e($chiave) ?>">
                <?= $campoPagina ?>

                <label for="oggetto-<?= $e($chiave) ?>">Oggetto</label>
                <input type="text" id="oggetto-<?= $e($chiave) ?>" name="oggetto" maxlength="200"
                       value="<?= $e($testi[$chiave]['oggetto']) ?>"
                       placeholder="<?= $e($voce['predefinito'][0]) ?>">

                <?= CampoContato::html('testo-' . $chiave, 'Testo', 'testo', Modelli::MAX_TESTO,
                    $testi[$chiave]['testo'], 12, false, $voce['predefinito'][1]) ?>
                <p class="form-hint">
                    Lasciando vuoto vale il testo che vedi in grigio. La chiusura si aggiunge da sola.
                </p>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Salva</button>
                    <button type="reset" class="btn btn-secondary">Annulla</button>
                    <button type="submit" class="btn btn-secondary" formaction="/admin/settings/email/prova">
                        Manda una prova a me
                    </button>
                </div>
            </form>
        </section>
    <?php endforeach; ?>
<?php endforeach; ?>
</div>

<?php if ($solo === null || isset($elenco[$solo])): ?>
<p class="card-meta">
    La prova parte<?= $myEmail !== null && $myEmail !== '' ? ' a ' . $e($myEmail) : ' al tuo indirizzo' ?>
    con quello che è scritto nei campi, anche se non l’hai ancora salvato, e con valori di esempio al posto
    dei segnaposto. Le prove degli incontri partono senza il file per il calendario.
</p>
<?php endif; ?>

<script src="/assets/js/quiz-open-count.js"></script>

<?php if ($lastUpdate !== null): ?>
    <p class="card-meta">
        Ultima modifica da questa pagina:
        <?= $e(date('d/m/Y H:i', strtotime((string) $lastUpdate['updated_at'])))
            . ($lastUpdate['full_name'] !== null ? ' — ' . $e((string) $lastUpdate['full_name']) : '')
            . '.' ?>
    </p>
<?php endif; ?>
