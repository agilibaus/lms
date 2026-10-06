<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Controllers\ProfileController;
use App\Core\Csrf;
use App\Core\GroupLogo;
use App\Core\Welcome;

/** @var array $user */
/** @var array $groups */
/** @var int $maxAvatarMb */

$userId = (int) $user['id'];
$hasAvatar = !empty($user['avatar_path']);
?>
<div class="page-header">
    <h1>Il mio profilo</h1>
    <p class="page-subtitle">
        <?= htmlspecialchars(Auth::roleLabel((string) $user['role'])) ?> ·
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
            <?php /* Chiesto da Elena il 06/10: chi carica la foto deve sapere
                     prima dove andra' a finire. La frase dice esattamente il
                     perimetro di `GroupPeers`, che e' la regola che lo fa
                     rispettare: se cambia una, va cambiata l'altra. */ ?>
            <p class="form-hint">
                La foto e la presentazione sono facoltative. Se le inserisci, compariranno nella pagina
                dei tuoi gruppi, dove le vedono i compagni di gruppo, il tutor e l'amministratore.
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

        <?php
        /*
         * Il contatore e' quello delle risposte aperte dei questionari
         * (patch 0108), con le stesse classi e lo stesso script: sopra il
         * campo, a destra, scala mentre si scrive, letto da un lettore di
         * schermo come descrizione del campo e non a ogni tasto. Le classi si
         * chiamano `quiz-open-*` perche' e' li' che e' nato.
         *
         * Il numero di partenza lo scrive il server, contando quello che c'e'
         * gia': senza JavaScript il contatore resta fermo, e qui il campo non
         * parte vuoto come una risposta nuova — «1000 caratteri rimasti»
         * sopra una presentazione gia' scritta sarebbe falso.
         */
        $bio = (string) ($user['bio'] ?? '');
        $restano = max(0, ProfileController::MAX_BIO_CHARS - mb_strlen($bio));
        ?>
        <label for="bio">Presentazione</label>
        <div class="quiz-open-wrap">
            <span class="quiz-open-count" id="bio-resta" data-max="<?= ProfileController::MAX_BIO_CHARS ?>">
                <?= $restano ?> <?= $restano === 1 ? 'carattere rimasto' : 'caratteri rimasti' ?>
            </span>
            <textarea id="bio" name="bio" rows="5" maxlength="<?= ProfileController::MAX_BIO_CHARS ?>"
                      class="quiz-open-answer" aria-describedby="bio-resta bio-aiuto"><?= htmlspecialchars($bio) ?></textarea>
        </div>
        <p class="form-hint" id="bio-aiuto">
            Due righe su di te: da dove arrivi, perché segui questi corsi. Facoltativa: se la scrivi, i
            compagni dei tuoi gruppi la leggono nella pagina del gruppo.
        </p>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Salva</button>
        </div>
    </form>
</section>

<section class="card">
    <h2>Password</h2>
    <p class="form-hint" style="margin-top: 0;">
        Per cambiarla ti verrà chiesta quella attuale. Le altre sessioni aperte con la
        password precedente vengono chiuse.
    </p>
    <p>
        <a href="<?= Auth::PASSWORD_PAGE ?>" class="btn btn-secondary">Cambia password</a>
    </p>
</section>

<?php /* Il benvenuto si rivede solo se un video c'e' davvero: una voce che
         porta a una pagina vuota e' peggio di una voce che manca. E' un
         collegamento discreto e non una scheda sua, perche' si usa una
         volta ogni tanto. */ ?>
<?php if (Welcome::configurato()): ?>
    <p class="profilo-benvenuto">
        <a href="<?= Welcome::PAGE ?>">Rivedi video di benvenuto</a>
    </p>
<?php endif; ?>

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
                        <a href="/gruppi/<?= (int) $group['id'] ?>"><?= htmlspecialchars((string) $group['name']) ?></a>
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

<script src="/assets/js/quiz-open-count.js"></script>
