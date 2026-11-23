<?php

declare(strict_types=1);

/**
 * La pagina d'errore dopo l'accesso, dentro l'applicazione (23/11, variante
 * del mockup approvata da Alessandro): il titolo dice che cosa e' successo,
 * il riquadro la frase di quel caso e il pulsante per tornare, uguale per
 * tutti gli errori. Vedi `App\Core\Errore`.
 *
 * @var string $pageTitle
 * @var string $messaggio
 */
?>
<div class="page-header">
    <h1><?= htmlspecialchars($pageTitle) ?></h1>
</div>

<section class="card">
    <p><?= htmlspecialchars($messaggio) ?></p>
    <p><a href="/" class="btn btn-primary">Torna alla pagina iniziale</a></p>
</section>
