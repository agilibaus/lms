<?php

declare(strict_types=1);

use App\Core\Csrf;

/**
 * @var string $libraryId
 * @var bool $hasKey
 * @var int $ttlHours
 * @var int $minTtl
 * @var int $maxTtl
 * @var bool $signing
 * @var array<string, string> $sources
 * @var array|null $lastUpdate
 */
?>
<div class="page-header">
    <a href="/admin/settings" class="back-link">← Impostazioni</a>
    <h1>Bunny Stream</h1>
    <p class="page-subtitle">
        Dove stanno i video dei corsi, e come si impedisce che un indirizzo copiato dagli
        strumenti per sviluppatori funzioni anche per chi non è iscritto.
    </p>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<?php if ($signing): ?>
    <div class="alert alert-success">
        La firma degli indirizzi è attiva: ogni iframe parte con un token che scade dopo
        <?= (int) $ttlHours ?> ore.
    </div>
<?php else: ?>
    <div class="alert alert-info">
        La firma degli indirizzi <strong>non</strong> è attiva: gli iframe partono senza token.
        Serve la chiave di sicurezza qui sotto, <em>e</em> la Token Authentication accesa nelle
        impostazioni della libreria su Bunny. Fai le due cose in quest'ordine: prima la chiave
        qui, poi l'interruttore su Bunny. Al contrario i video smetterebbero di funzionare
        finché non torni qui.
    </div>
<?php endif; ?>

<form action="/admin/settings/bunny" method="post" class="form">
    <?= Csrf::field() ?>

    <section class="card">
        <h2>Libreria</h2>

        <label for="BUNNY_LIBRARY_ID">Identificativo della libreria</label>
        <input type="text" id="BUNNY_LIBRARY_ID" name="BUNNY_LIBRARY_ID"
               value="<?= htmlspecialchars($libraryId) ?>" autocomplete="off">
        <p class="form-hint">
            Il numero della Video Library su Bunny. Lasciandolo vuoto vale quello del file
            <code>.env</code>, se c'è.
            <?php if (($sources['BUNNY_LIBRARY_ID'] ?? '') === 'file'): ?>
                <strong>Adesso arriva dal file.</strong>
            <?php endif; ?>
        </p>
    </section>

    <section class="card">
        <h2>Firma degli indirizzi</h2>

        <label for="BUNNY_TOKEN_KEY">Chiave di sicurezza (token security key)</label>
        <input type="password" id="BUNNY_TOKEN_KEY" name="BUNNY_TOKEN_KEY" autocomplete="off"
               placeholder="<?= $hasKey ? 'impostata — scrivi qui per sostituirla' : 'nessuna chiave impostata' ?>">
        <p class="form-hint">
            Si copia dalle impostazioni della libreria su Bunny. Non viene mai rimandata al
            browser: se lasci il campo vuoto resta quella di prima. Resta sul server anche
            quando i video vengono mostrati — al browser arriva solo il risultato del calcolo,
            da cui la chiave non si ricava.
        </p>

        <?php if ($hasKey): ?>
            <label class="checkbox-label">
                <input type="checkbox" name="clear_key" value="1">
                <span>Cancella la chiave salvata (la firma si spegne, e i video funzionano solo
                      se la Token Authentication è spenta anche su Bunny)</span>
            </label>
        <?php endif; ?>

        <label for="BUNNY_TOKEN_TTL_HOURS">Durata del token, in ore</label>
        <input type="number" id="BUNNY_TOKEN_TTL_HOURS" name="BUNNY_TOKEN_TTL_HOURS"
               value="<?= (int) $ttlHours ?>" min="<?= (int) $minTtl ?>" max="<?= (int) $maxTtl ?>" step="1">
        <p class="form-hint">
            Da <?= (int) $minTtl ?> a <?= (int) $maxTtl ?> ore; il valore predefinito è 12.
            <strong>Non limita lo studente</strong>: il token nasce nuovo a ogni apertura della
            lezione, quindi si può riprendere un video anche giorni dopo. Limita due cose sole:
            per quanto resta valido un indirizzo copiato e passato ad altri, e dopo quanto una
            pagina lasciata aperta va ricaricata.
        </p>
    </section>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Salva</button>
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
