<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\GroupLogo;

/** @var array $user */
/** @var array $groups */
/** @var int $maxAvatarMb */

$userId = (int) $user['id'];
$hasAvatar = !empty($user['avatar_path']);
?>
<div class="page-header">
    <h1>Il mio profilo</h1>
    <p class="page-subtitle">
        <?= htmlspecialchars((string) $user['role']) ?> ·
        iscritto dal <?= date('d/m/Y', strtotime((string) $user['created_at'])) ?>
    </p>
</div>

<?php require __DIR__ . '/../admin/_flash.php'; ?>

<section class="card">
    <h2>Immagine</h2>

    <div class="avatar-editor">
        <?php if ($hasAvatar): ?>
            <img class="avatar avatar-lg" src="/utenti/<?= $userId ?>/immagine" alt="La tua immagine del profilo">
        <?php else: ?>
            <span class="avatar avatar-lg avatar-placeholder" aria-hidden="true">
                <?= htmlspecialchars(mb_strtoupper(mb_substr((string) $user['full_name'], 0, 1))) ?>
            </span>
        <?php endif; ?>

        <div class="avatar-editor-actions">
            <form action="/profilo/immagine" method="post" enctype="multipart/form-data" class="form form-inline">
                <?= Csrf::field() ?>
                <input type="file" name="avatar" accept="image/*" required aria-label="Nuova immagine del profilo">
                <button type="submit" class="btn btn-secondary"><?= $hasAvatar ? 'Sostituisci' : 'Carica' ?></button>
            </form>
            <p class="form-hint">
                JPG, PNG, GIF o WebP fino a <?= $maxAvatarMb ?>&nbsp;MB. Viene ritagliata quadrata al centro
                e ridotta: non serve prepararla prima.
            </p>

            <?php if ($hasAvatar): ?>
                <form action="/profilo/immagine/elimina" method="post"
                      onsubmit="return confirm('Rimuovere l’immagine del profilo?');">
                    <?= Csrf::field() ?>
                    <button type="submit" class="link-btn link-btn-danger">Rimuovi immagine</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="card">
    <h2>Dati</h2>
    <form action="/profilo" method="post" class="form">
        <?= Csrf::field() ?>

        <label for="full_name">Nome e cognome</label>
        <input type="text" id="full_name" name="full_name" maxlength="150" required
               value="<?= htmlspecialchars((string) $user['full_name']) ?>">

        <label for="email">Email</label>
        <input type="email" id="email" value="<?= htmlspecialchars((string) $user['email']) ?>" disabled>
        <p class="form-hint">
            L'email è la credenziale di accesso e non si cambia da qui: scrivi a chi amministra la
            piattaforma.
        </p>

        <label for="city">Città</label>
        <input type="text" id="city" name="city" maxlength="120"
               value="<?= htmlspecialchars((string) ($user['city'] ?? '')) ?>">

        <label for="phone">Telefono</label>
        <input type="text" id="phone" name="phone" maxlength="40"
               value="<?= htmlspecialchars((string) ($user['phone'] ?? '')) ?>">

        <label for="bio">Presentazione</label>
        <textarea id="bio" name="bio" rows="5" maxlength="2000"><?= htmlspecialchars((string) ($user['bio'] ?? '')) ?></textarea>
        <p class="form-hint">Due righe su di te: da dove arrivi, perché segui questi corsi. Facoltativa.</p>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Salva</button>
        </div>
    </form>
</section>

<?php if ($groups !== []): ?>
    <section class="card">
        <h2>I miei gruppi</h2>
        <ul class="assign-list">
            <?php foreach ($groups as $group): ?>
                <li>
                    <?php $logo = GroupLogo::url($group); ?>
                    <?php if ($logo !== null): ?>
                        <img src="<?= htmlspecialchars($logo) ?>" alt="" class="group-logo" loading="lazy">
                    <?php else: ?>
                        <span class="group-logo group-logo-placeholder"
                              style="--logo-hue: <?= GroupLogo::hue((int) $group['id']) ?>;" aria-hidden="true">
                            <?= htmlspecialchars(GroupLogo::initials((string) $group['name'])) ?>
                        </span>
                    <?php endif; ?>

                    <span class="assign-info">
                        <?= htmlspecialchars((string) $group['name']) ?>
                        <span class="cell-sub">
                            <?= $group['tutor_name'] !== null
                                ? 'tutor: ' . htmlspecialchars((string) $group['tutor_name'])
                                : 'nessun tutor' ?>
                            · dal <?= date('d/m/Y', strtotime((string) $group['joined_at'])) ?>
                        </span>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
