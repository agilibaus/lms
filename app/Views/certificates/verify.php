<?php

declare(strict_types=1);

/**
 * Pagina pubblica di verifica: nessun login richiesto, nessun dato di contatto.
 *
 * @var string $code
 * @var array|null $certificate
 */
$valid = $certificate !== null && $certificate['revoked_at'] === null;
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifica certificato · Pistacchio LMS</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="centered-page">
<main class="verify-card">
    <p class="verify-brand">Pistacchio LMS</p>
    <h1>Verifica certificato</h1>

    <?php if ($certificate === null): ?>
        <p class="verify-status verify-invalid">Nessun certificato corrisponde al codice indicato.</p>
        <p class="verify-code"><?= htmlspecialchars($code) ?></p>
    <?php else: ?>
        <p class="verify-status <?= $valid ? 'verify-valid' : 'verify-invalid' ?>">
            <?= $valid ? 'Certificato valido' : 'Certificato revocato' ?>
        </p>
        <dl class="verify-details">
            <dt>Intestatario</dt>
            <dd><?= htmlspecialchars((string) $certificate['full_name']) ?></dd>
            <dt>Corso</dt>
            <dd><?= htmlspecialchars((string) $certificate['course_title']) ?></dd>
            <dt>Data di rilascio</dt>
            <dd><?= htmlspecialchars((string) $certificate['issued_at']) ?></dd>
            <?php if (!$valid): ?>
                <dt>Revocato il</dt>
                <dd><?= htmlspecialchars((string) $certificate['revoked_at']) ?></dd>
            <?php endif; ?>
            <dt>Codice</dt>
            <dd><code><?= htmlspecialchars((string) $certificate['certificate_code']) ?></code></dd>
        </dl>
    <?php endif; ?>
</main>
</body>
</html>
