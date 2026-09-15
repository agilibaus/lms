<?php

declare(strict_types=1);

defined('LMS_INSTALLER') || exit('Accesso diretto non consentito.');

/** @var array<int, array{label: string, ok: bool, blocking: bool, detail: string}> $checks */
/** @var bool $blocked */
/** @var string $token */
?>
<p>Controllo dell'ambiente prima di iniziare. Le voci in rosso vanno risolte; quelle in giallo
   riguardano funzioni che potrai attivare anche in seguito.</p>

<section class="card">
    <ul class="check-list">
        <?php foreach ($checks as $check): ?>
            <?php
            $state = $check['ok'] ? 'ok' : ($check['blocking'] ? 'fail' : 'warn');
            $symbol = $check['ok'] ? '&check;' : ($check['blocking'] ? '&times;' : '!');
            ?>
            <li>
                <span class="check-mark check-<?= $state ?>"><?= $symbol ?></span>
                <span>
                    <span class="check-label"><?= htmlspecialchars($check['label']) ?></span>
                    <span class="check-detail"><?= htmlspecialchars($check['detail']) ?></span>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<?php if ($blocked): ?>
    <div class="alert alert-error">
        Alcuni requisiti indispensabili non sono soddisfatti. Risolvili e ricarica questa pagina.
    </div>
    <p><a href="?step=requirements" class="btn btn-secondary">Ricontrolla</a></p>
<?php else: ?>
    <p><a href="?step=database" class="btn btn-primary">Continua</a></p>
<?php endif; ?>
