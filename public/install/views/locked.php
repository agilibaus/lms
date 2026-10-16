<?php

declare(strict_types=1);

defined('LMS_INSTALLER') || exit('Accesso diretto non consentito.');

/** @var Installer $installer */
?>
<div class="alert alert-warning">
    Pistacchio LMS risulta già installato: la procedura guidata è disattivata.
</div>

<p>
    Se devi davvero reinstallare da zero — perdendo i dati esistenti — elimina i file
    <code>.env</code> e <code>storage/installed.lock</code> e svuota il database, poi ricarica
    questa pagina.
</p>

<p>Altrimenti, la strada giusta è l'accesso normale alla piattaforma.</p>

<p><a href="/login" class="btn btn-primary">Vai all'accesso</a></p>
