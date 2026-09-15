<?php

declare(strict_types=1);

/**
 * Template del certificato reso in PDF da Dompdf (A4 orizzontale).
 * Niente flexbox/grid: Dompdf supporta in modo affidabile solo il box model classico.
 *
 * @var string $code
 * @var string $fullName
 * @var string $courseTitle
 * @var \DateTimeImmutable $issuedAt
 * @var string $verifyUrl
 */
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 0; }
        body {
            margin: 0;
            font-family: DejaVu Sans, sans-serif;
            color: #1f2a24;
        }
        .sheet {
            margin: 28px;
            padding: 44px 56px;
            border: 3px solid #7ba05b;
            height: 470px;
            text-align: center;
        }
        .brand {
            font-size: 13px;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #7ba05b;
        }
        .title {
            margin-top: 34px;
            font-size: 32px;
            letter-spacing: 1px;
        }
        .intro {
            margin-top: 26px;
            font-size: 13px;
            color: #5a6b60;
        }
        .name {
            margin-top: 10px;
            font-size: 27px;
            font-weight: bold;
        }
        .course {
            margin-top: 22px;
            font-size: 18px;
        }
        .rule {
            margin: 26px auto 0;
            width: 180px;
            border-top: 1px solid #c9d6c0;
        }
        .meta {
            margin-top: 22px;
            font-size: 11px;
            color: #5a6b60;
            line-height: 1.7;
        }
        .code {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 12px;
            letter-spacing: 1px;
        }
    </style>
</head>
<body>
<div class="sheet">
    <div class="brand">Pistacchio LMS</div>

    <div class="title">Attestato di completamento</div>

    <div class="intro">Si certifica che</div>
    <div class="name"><?= htmlspecialchars($fullName) ?></div>

    <div class="intro">ha completato con successo il corso</div>
    <div class="course"><?= htmlspecialchars($courseTitle) ?></div>

    <div class="rule"></div>

    <div class="meta">
        Rilasciato il <?= htmlspecialchars($issuedAt->format('d/m/Y')) ?><br>
        Codice di verifica: <span class="code"><?= htmlspecialchars($code) ?></span>
        <?php if (trim($verifyUrl, '/') !== ''): ?>
            <br>Verifica online: <?= htmlspecialchars($verifyUrl) ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
