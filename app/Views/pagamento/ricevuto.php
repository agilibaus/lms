<?php

declare(strict_types=1);

/**
 * La pagina d'attesa dopo il pagamento su Stripe (06/11, variante 1A): dentro
 * Pistacchio, con il menu. Si ricarica da sola (intestazione `Refresh` di
 * `StripeController::ricevuto`), al massimo per 5 secondi, poi porta a «I miei
 * corsi». I tre punti si accendono a turno; con il movimento ridotto restano
 * fermi.
 */
?>
<section class="pagamento-attesa" role="status">
    <h1>Pagamento ricevuto</h1>
    <p>Un momento: stiamo preparando il tuo corso.</p>
    <div class="pagamento-attesa-punti" aria-hidden="true"><span></span><span></span><span></span></div>
    <p class="form-hint">Tra pochi secondi tornerai a «I miei corsi».</p>
</section>
