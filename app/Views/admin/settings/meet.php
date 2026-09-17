<?php

declare(strict_types=1);

use App\Core\Csrf;

/**
 * @var array<string, string> $values
 * @var string $keyPath
 * @var bool $keyPresent
 * @var string|null $keyAccount
 * @var bool $configured
 * @var array<string, string> $sources
 * @var array|null $lastUpdate
 * @var string $defaultTimezone
 */
?>
<div class="page-header">
    <h1>Google Meet</h1>
    <p class="page-subtitle">
        Serve a creare il link Meet insieme all'evento di calendario quando programmi una sessione live.
        Senza questa configurazione le sessioni restano gestibili incollando a mano un link.
        Quello che salvi qui ha la precedenza sul file <code>.env</code>.
    </p>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<section class="card">
    <h2>Stato</h2>
    <?php if ($configured): ?>
        <p>Credenziali presenti: la piattaforma può creare eventi con link Meet.</p>
    <?php else: ?>
        <p class="empty-state">
            Configurazione incompleta: i link Meet vanno inseriti a mano nelle sessioni live.
        </p>
    <?php endif; ?>

    <?php if ($keyAccount !== null): ?>
        <p class="card-meta">Account di servizio: <code><?= htmlspecialchars($keyAccount) ?></code></p>
    <?php elseif ($keyPath !== '' && !$keyPresent): ?>
        <p class="card-meta">
            Il percorso configurato non corrisponde a nessun file: <code><?= htmlspecialchars($keyPath) ?></code>
        </p>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Credenziali e calendario</h2>

    <form action="/admin/settings/meet" method="post" class="form" enctype="multipart/form-data">
        <?= Csrf::field() ?>

        <label for="key"><?= $keyPresent ? 'Sostituisci la chiave' : 'Chiave dell\'account di servizio' ?></label>
        <input type="file" id="key" name="key" accept="application/json,.json">
        <p class="form-hint">
            Il file JSON scaricato da Google Cloud quando crei l'account di servizio. Viene salvato in
            <code>storage/google</code>, fuori dalle cartelle raggiungibili dal web, e in tabella resta
            solo il percorso. Contiene una chiave privata: trattalo come una password.
        </p>

        <label for="GOOGLE_IMPERSONATE_EMAIL">Indirizzo da impersonare</label>
        <input type="email" id="GOOGLE_IMPERSONATE_EMAIL" name="GOOGLE_IMPERSONATE_EMAIL"
               value="<?= htmlspecialchars($values['GOOGLE_IMPERSONATE_EMAIL']) ?>"
               placeholder="corsi@tuodominio.it">
        <p class="form-hint">
            L'utente Workspace per conto del quale vengono creati gli eventi. Richiede la delega a
            livello di dominio concessa all'account di servizio.
        </p>

        <label for="GOOGLE_CALENDAR_ID">Calendario</label>
        <input type="text" id="GOOGLE_CALENDAR_ID" name="GOOGLE_CALENDAR_ID"
               value="<?= htmlspecialchars($values['GOOGLE_CALENDAR_ID']) ?>" placeholder="primary">
        <p class="form-hint">
            <code>primary</code> è il calendario principale dell'utente impersonato; in alternativa
            l'indirizzo di un calendario condiviso.
        </p>

        <label for="GOOGLE_CALENDAR_TIMEZONE">Fuso orario</label>
        <input type="text" id="GOOGLE_CALENDAR_TIMEZONE" name="GOOGLE_CALENDAR_TIMEZONE"
               value="<?= htmlspecialchars($values['GOOGLE_CALENDAR_TIMEZONE']) ?>"
               placeholder="<?= htmlspecialchars($defaultTimezone) ?>">
        <p class="form-hint">Nel formato <code>Europe/Rome</code>. Vuoto: vale quello della piattaforma.</p>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Salva</button>
        </div>
    </form>

    <?php if ($keyPresent): ?>
        <form action="/admin/settings/meet/chiave/elimina" method="post"
              onsubmit="return confirm('Rimuovere la chiave? Le sessioni live andranno gestite con link inseriti a mano.');">
            <?= Csrf::field() ?>
            <button type="submit" class="link-btn link-btn-danger">Rimuovi la chiave</button>
        </form>
    <?php endif; ?>

    <?php if ($lastUpdate !== null): ?>
        <p class="card-meta">
            Ultima modifica da questa pagina:
            <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $lastUpdate['updated_at'])))
                . ($lastUpdate['full_name'] !== null ? ' — ' . htmlspecialchars((string) $lastUpdate['full_name']) : '')
                . '.' ?>
        </p>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Prova di connessione</h2>
    <p class="card-meta">
        Legge il calendario configurato senza creare né modificare niente. Verifica in un colpo solo
        le credenziali, la delega e il fatto che quel calendario sia visibile all'utente impersonato.
    </p>

    <form action="/admin/settings/meet/prova" method="post">
        <?= Csrf::field() ?>
        <button type="submit" class="btn btn-secondary" <?= $configured ? '' : 'disabled' ?>>
            Prova la connessione
        </button>
    </form>
</section>
