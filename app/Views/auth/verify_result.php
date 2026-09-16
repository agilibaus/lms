<?php

declare(strict_types=1);

/** @var bool $success */

ob_start();
?>
<?php if ($success): ?>
    <div class="alert alert-success">Indirizzo confermato: il tuo account è attivo.</div>
    <p>Ora puoi accedere e iscriverti ai corsi disponibili nel catalogo.</p>
    <p><a href="/login" class="btn btn-primary btn-block">Accedi</a></p>
<?php else: ?>
    <div class="alert alert-error">Questo link non è valido, è scaduto oppure è già stato usato.</div>
    <p>Se non hai ancora confermato l'indirizzo, puoi farti mandare un nuovo link.</p>
    <p><a href="/register/rinvia" class="btn btn-primary btn-block">Richiedi un nuovo link</a></p>
    <p class="auth-links"><a href="/login">Torna all'accesso</a></p>
<?php endif; ?>
<?php
$cardContent = ob_get_clean();
require __DIR__ . '/_card.php';
