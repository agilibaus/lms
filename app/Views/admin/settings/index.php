<?php

declare(strict_types=1);

/**
 * @var bool $canSettings
 * @var bool $canPermissions
 */

/*
 * Le schede stanno in un elenco invece che nel markup una per una: aggiungerne
 * una domani e' una riga, e la condizione di visibilita' resta accanto alla
 * scheda a cui appartiene.
 */
$sezioni = [
    [
        'titolo' => 'Posta elettronica',
        'href' => '/admin/settings/posta',
        'testo' => 'Come partono le email della piattaforma: server SMTP, mittente, e la prova di invio.',
        'visibile' => $canSettings,
    ],
    [
        'titolo' => 'Inviti sessioni live',
        'href' => '/admin/settings/inviti',
        'testo' => 'Oggetto e testo delle tre email degli incontri dal vivo: invito, cambio di orario, annullamento.',
        'visibile' => $canSettings,
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
        'titolo' => 'Permessi',
        'href' => '/admin/permissions',
        'testo' => 'Che cosa può fare ciascun ruolo. È la pagina più delicata del pannello: una spunta tolta qui cambia il lavoro di qualcuno.',
        'visibile' => $canPermissions,
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
            <span class="settings-card-title"><?= htmlspecialchars($sezione['titolo']) ?></span>
            <span class="settings-card-text"><?= htmlspecialchars($sezione['testo']) ?></span>
        </a>
    <?php endforeach; ?>
</div>
