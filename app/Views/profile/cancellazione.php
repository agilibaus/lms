<?php

declare(strict_types=1);

use App\Core\Csrf;

/**
 * «Chiedi la cancellazione dei tuoi dati» (18/11, Alessandro): che cosa
 * succede, prima di confermare. La conferma chiude l'accesso subito.
 */
?>
<div class="page-header">
    <a href="/profilo" class="back-link"><span class="back-link-testo">Il mio profilo</span></a>
    <h1>Chiedi la cancellazione dei tuoi dati</h1>
</div>

<section class="card">
    <p>Se confermi:</p>
    <ul class="privacy-elenco">
        <li>l’accesso a Pistacchio si chiude subito, e non potrai più entrare;</li>
        <li>non vedrai più i tuoi corsi, i tuoi progressi e i tuoi attestati;</li>
        <li>entro un mese cancelliamo il tuo account e i tuoi dati, tranne quelli che la legge ci obbliga a conservare.</li>
    </ul>
    <p>Se hai attestati che ti servono, scaricali prima di confermare.</p>

    <form action="/profilo/cancellazione" method="post" class="privacy-azioni">
        <?= Csrf::field() ?>
        <button type="submit" class="btn btn-pericolo">Chiedi la cancellazione</button>
        <a href="/profilo" class="btn btn-secondary">Annulla</a>
    </form>
</section>
