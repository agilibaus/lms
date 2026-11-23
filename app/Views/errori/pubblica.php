<?php

declare(strict_types=1);

/**
 * La pagina d'errore senza accesso, nella cornice delle pagine pubbliche
 * (23/11): la frase nel riquadro rosso e il pulsante per l'accesso. Il
 * titolo lo stampa `_card.php`. Vedi `App\Core\Errore`.
 *
 * @var string $pageTitle
 * @var string $messaggio
 */

ob_start();
?>
<div class="alert alert-error"><?= htmlspecialchars($messaggio) ?></div>
<p><a href="/login" class="btn btn-primary btn-block">Vai all'accesso</a></p>
<?php
$cardContent = ob_get_clean();
require __DIR__ . '/../auth/_card.php';
