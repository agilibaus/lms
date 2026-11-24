<?php

declare(strict_types=1);

use App\Core\AuthLayout;
use App\Core\Quando;

/**
 * Pagina pubblica di verifica: nessun login richiesto, nessun dato di contatto.
 *
 * Nella cornice delle pagine pubbliche (24/11, Alessandro, variante B del
 * mockup): sempre il riquadro centrato, qualunque sia l'aspetto scelto in
 * Impostazioni › Aspetto. La apre quasi sempre un estraneo (chi controlla
 * un attestato): la presentazione affiancata non gli serve, e il risultato
 * si vede subito, anche sul telefono. L'esito negli avvisi verde e rosso
 * delle altre pagine pubbliche.
 *
 * @var string $code
 * @var array|null $certificate
 */
$valid = $certificate !== null && $certificate['revoked_at'] === null;
$pageTitle = 'Verifica certificato';
$aspettoForzato = AuthLayout::GUSCIO;

ob_start();
?>
<p class="auth-subtitle">Controllo di un attestato di frequenza</p>

<?php if ($certificate === null): ?>
    <div class="alert alert-error">Nessun certificato corrisponde al codice indicato.</div>
    <dl class="verify-details">
        <dt>Codice cercato</dt>
        <dd><code><?= htmlspecialchars($code) ?></code></dd>
    </dl>
<?php else: ?>
    <div class="alert <?= $valid ? 'alert-success' : 'alert-error' ?>">
        <?= $valid ? 'Certificato valido.' : 'Certificato revocato: non è più valido.' ?>
    </div>
    <dl class="verify-details">
        <dt>Intestatario</dt>
        <dd><?= htmlspecialchars((string) $certificate['full_name']) ?></dd>
        <dt>Corso</dt>
        <dd><?= htmlspecialchars((string) $certificate['course_title']) ?></dd>
        <dt>Data di rilascio</dt>
        <dd><?= htmlspecialchars(Quando::dataOra((string) $certificate['issued_at'])) ?></dd>
        <?php if (!$valid): ?>
            <dt>Revocato il</dt>
            <dd><?= htmlspecialchars(Quando::dataOra((string) $certificate['revoked_at'])) ?></dd>
        <?php endif; ?>
        <dt>Codice</dt>
        <dd><code><?= htmlspecialchars((string) $certificate['certificate_code']) ?></code></dd>
    </dl>
<?php endif; ?>
<?php
$cardContent = ob_get_clean();
require __DIR__ . '/../auth/_card.php';
