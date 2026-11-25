<?php

declare(strict_types=1);

/**
 * @var int $comandiDaControllare comandi pianificati fermi o mai partiti (17/11)
 * @var int $richiestePrivacy richieste di cancellazione dei dati in attesa (18/11)
 * @var bool $canSettings
 * @var bool $canPermissions
 * @var bool $canWelcome «Video di benvenuto»: anche il supervisore (30/10)
 */

/*
 * Le schede stanno in un elenco invece che nel markup una per una: aggiungerne
 * una domani e' una riga, e la condizione di visibilita' resta accanto alla
 * scheda a cui appartiene.
 */
$sezioni = [
    [
        'titolo' => 'Server di posta elettronica',
        'href' => '/admin/settings/posta',
        'testo' => 'Come partono le email della piattaforma: il server SMTP e la prova di invio.',
        'visibile' => $canSettings,
    ],
    [
        'titolo' => 'Messaggi email',
        'href' => '/admin/settings/email',
        'testo' => 'Nome dell’organizzazione, mittente e testo di ogni email inviata dalla piattaforma.',
        'visibile' => $canSettings,
    ],
    [
        'titolo' => 'Video di benvenuto',
        'href' => '/admin/settings/benvenuto',
        'testo' => 'Il video che uno studente vede al primo accesso alla piattaforma.',
        'visibile' => $canWelcome ?? $canSettings,
    ],
    [
        'titolo' => 'Bunny Stream',
        'href' => '/admin/settings/bunny',
        'testo' => 'Dove stanno i video dei corsi, e la firma che impedisce di guardarli senza essere iscritti.',
        'visibile' => $canSettings,
    ],
    [
        'titolo' => 'Google Meet',
        'href' => '/admin/settings/meet',
        'testo' => 'Account di servizio e calendario con cui vengono creati gli incontri dal vivo.',
        'visibile' => $canSettings,
    ],
    [
        'titolo' => 'Aspetto',
        'href' => '/admin/settings/aspetto',
        'testo' => 'Come si presentano le pagine di accesso, registrazione e password.',
        'visibile' => $canSettings,
    ],
    [
        'titolo' => 'Ruoli',
        'href' => '/admin/permissions',
        'testo' => 'Che cosa fa ogni ruolo, e i cinque interruttori che decide l’Amministratore. Un interruttore spento cambia il lavoro di qualcuno.',
        'visibile' => $canPermissions,
    ],
    [
        'titolo' => 'Pagamenti',
        'href' => '/admin/settings/pagamenti',
        'testo' => 'I corsi a pagamento con Stripe: se si possono creare, e i pagamenti ricevuti.',
        'visibile' => $canSettings,
    ],
    [
        'titolo' => 'Privacy',
        'href' => '/admin/settings/privacy',
        'testo' => 'Il testo dell’informativa, le sue versioni e le richieste di cancellazione dei dati.',
        'visibile' => $canSettings,
        // Un punto esclamativo se qualcuno ha chiesto la cancellazione (18/11).
        'avviso' => ($richiestePrivacy ?? 0) > 0,
    ],
    [
        'titolo' => 'Comandi pianificati',
        'href' => '/admin/settings/comandi',
        'testo' => 'Quando hanno girato l’ultima volta i comandi che il server lancia da solo: avvisi, inviti, rilascio dei moduli.',
        'visibile' => $canSettings,
        // Un punto esclamativo se qualcuno e' fermo o mai partito (17/11).
        'avviso' => ($comandiDaControllare ?? 0) > 0,
    ],
    [
        'titolo' => 'Informazioni su Pistacchio LMS',
        'href' => '/admin/settings/informazioni',
        'testo' => 'La versione installata e il collegamento al codice sorgente su GitHub.',
        'visibile' => true,
    ],
];
?>
<div class="page-header">
    <h1>Impostazioni</h1>
    <p class="page-subtitle">
        La configurazione della piattaforma. Corsi, gruppi e utenti restano fuori di qui:
        sono lavoro di tutti i giorni, non configurazione.
    </p>
</div>

<div class="settings-grid">
    <?php foreach ($sezioni as $sezione): ?>
        <?php if (!$sezione['visibile']) {
            continue;
        } ?>
        <a class="settings-card" href="<?= $sezione['href'] ?>">
            <span class="settings-card-title"><?= htmlspecialchars($sezione['titolo']) ?><?php if (!empty($sezione['avviso'])): ?><span class="settings-card-avviso" aria-hidden="true">!</span><span class="sr-only"> (da controllare)</span><?php endif; ?></span>
            <span class="settings-card-text"><?= htmlspecialchars($sezione['testo']) ?></span>
        </a>
    <?php endforeach; ?>
</div>
