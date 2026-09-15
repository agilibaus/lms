<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var array|null $user */
/** @var array $tutors */
/** @var string[] $roles */
/** @var int $minPasswordLength */

$isEdit = $user !== null;
$action = $isEdit ? '/admin/users/' . (int) $user['id'] : '/admin/users';
$currentRole = $user['role'] ?? 'studente';
?>
<div class="page-header">
    <a href="/admin/users" class="back-link">&larr; Utenti</a>
    <h1><?= $isEdit ? 'Modifica utente' : 'Nuovo utente' ?></h1>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<form action="<?= htmlspecialchars($action) ?>" method="post" class="form" data-user-form>
    <?= Csrf::field() ?>

    <label for="full_name">Nome e cognome</label>
    <input type="text" id="full_name" name="full_name" maxlength="150" required
           value="<?= htmlspecialchars((string) ($user['full_name'] ?? '')) ?>">

    <label for="email">Email</label>
    <input type="email" id="email" name="email" maxlength="190" required
           value="<?= htmlspecialchars((string) ($user['email'] ?? '')) ?>">

    <label for="role">Ruolo</label>
    <select id="role" name="role" data-role-select>
        <?php foreach ($roles as $role): ?>
            <option value="<?= htmlspecialchars($role) ?>" <?= $currentRole === $role ? 'selected' : '' ?>>
                <?= htmlspecialchars($role) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <?php if (count($roles) > 1): ?>
        <div data-tutor-field <?= $currentRole === 'assistente' ? '' : 'hidden' ?>>
            <label for="supervising_tutor_id">Tutor di riferimento (solo per gli assistenti)</label>
            <select id="supervising_tutor_id" name="supervising_tutor_id">
                <option value="0">— nessuno —</option>
                <?php foreach ($tutors as $tutor): ?>
                    <option value="<?= (int) $tutor['id'] ?>"
                        <?= (int) ($user['supervising_tutor_id'] ?? 0) === (int) $tutor['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $tutor['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <label class="checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= !$isEdit || (int) $user['is_active'] === 1 ? 'checked' : '' ?>>
        Account attivo (un account disattivato non può accedere)
    </label>

    <?php if (!$isEdit): ?>
        <label for="password">Password iniziale</label>
        <input type="password" id="password" name="password" required minlength="<?= (int) $minPasswordLength ?>"
               autocomplete="new-password">
        <p class="form-hint">Almeno <?= (int) $minPasswordLength ?> caratteri. Comunicala all'utente: non viene inviata alcuna email.</p>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Salva' : 'Crea utente' ?></button>
        <a href="/admin/users" class="btn btn-secondary">Annulla</a>
    </div>
</form>

<?php if ($isEdit): ?>
    <section class="card">
        <h2>Reimposta password</h2>
        <form action="/admin/users/<?= (int) $user['id'] ?>/password" method="post" class="form form-inline">
            <?= Csrf::field() ?>
            <input type="password" name="password" required minlength="<?= (int) $minPasswordLength ?>"
                   placeholder="Nuova password" autocomplete="new-password" aria-label="Nuova password">
            <button type="submit" class="btn btn-secondary">Aggiorna password</button>
        </form>
    </section>
<?php endif; ?>

<script>
    document.querySelectorAll('[data-user-form]').forEach(function (form) {
        var select = form.querySelector('[data-role-select]');
        var field = form.querySelector('[data-tutor-field]');

        if (!select || !field) {
            return;
        }

        select.addEventListener('change', function () {
            field.hidden = select.value !== 'assistente';
        });
    });
</script>
