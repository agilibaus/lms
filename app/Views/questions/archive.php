<?php

declare(strict_types=1);

/**
 * La pagina «L'esperto risponde» (09/10, Elena; prima «Domande e
 * risposte»), per lo studente e per il tutor: l'archivio di un corso. Dal
 * 17/10 il corso si sceglie entrando nel corso: la voce sta nel ramo del
 * corso della sidebar, e porta qui con `?corso=` (`CorsoCorrente`).
 *
 * @var array<string, mixed>|null $corso
 * @var list<array<string, mixed>> $archivio
 * @var string $cerca
 * @var int $totale
 * @var bool $puoChiedere
 */
?>
<div class="page-header">
    <?php /* Il corso si sceglie entrando nel corso (17/10, variante C della
             sidebar): qui niente menu dei corsi, ma il ritorno al corso. */ ?>
    <?php if ($corso !== null): ?>
        <a href="/courses/<?= (int) $corso['id'] ?>" class="back-link"><span class="back-link-testo"><?= htmlspecialchars((string) $corso['title']) ?></span></a>
    <?php endif; ?>
    <h1>L'esperto risponde</h1>
</div>

<?php if ($corso === null): ?>
    <p class="empty-state">Non ci sono ancora corsi da mostrare.</p>
<?php else: ?>
    <section class="card qa-archivio" aria-labelledby="qa-archivio-titolo">
        <h2 id="qa-archivio-titolo"><?= htmlspecialchars((string) $corso['title']) ?></h2>
        <?php
        $indirizzo = '/domande-e-risposte';
        $campiNascosti = ['corso' => (string) (int) $corso['id']];
        $chiedi = $puoChiedere ? '/courses/' . (int) $corso['id'] . '?chiedi=1#domande' : null;
        require __DIR__ . '/_archivio.php';
        ?>
    </section>
<?php endif; ?>
