<?php

declare(strict_types=1);

use App\Core\CourseCover;

/**
 * Sostituto della copertina quando il corso non ne ha una.
 *
 * Le iniziali e una tinta stabile derivata dall'identificativo servono a
 * distinguere i corsi in un elenco dove qualcuno ha l'immagine e qualcuno no:
 * una fila di rettangoli tutti uguali sembra una pagina non caricata.
 *
 * aria-hidden perche' non aggiunge niente a chi legge subito sotto il titolo.
 *
 * @var array $course
 */

$hue = CourseCover::hue((int) $course['id']);
?>
<div class="course-card-cover-placeholder"
     style="--cover-hue: <?= $hue ?>;"
     aria-hidden="true">
    <span><?= htmlspecialchars(CourseCover::initials((string) $course['title'])) ?></span>
</div>
