<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var array|null $user */
/** @var array $tutors */
/** @var string[] $roles */
/** @var int $minPasswordLength */
/** @var array $groups */
/** @var array $availableGroups */
/** @var bool $canManageGroups */

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
        <h2>Gruppi</h2>
        <p class="card-meta">
            I gruppi a cui l'utente partecipa. Entrando in un gruppo viene iscritto ai suoi corsi;
            uscendo, le iscrizioni già create restano attive.
        </p>

        <?php if ($groups === []): ?>
            <p class="empty-state-small">Non partecipa a nessun gruppo.</p>
        <?php else: ?>
            <ul class="assign-list">
                <?php foreach ($groups as $group): ?>
                    <li>
                        <span class="assign-info">
                            <?php if ($canManageGroups): ?>
                                <a href="/admin/groups/<?= (int) $group['id'] ?>/edit"><?= htmlspecialchars((string) $group['name']) ?></a>
                            <?php else: ?>
                                <?= htmlspecialchars((string) $group['name']) ?>
                            <?php endif; ?>
                            <span class="cell-sub">
                                <?= $group['tutor_name'] !== null
                                    ? 'tutor: ' . htmlspecialchars((string) $group['tutor_name'])
                                    : 'nessun tutor' ?>
                                · <?= (int) $group['course_count'] === 1 ? '1 corso' : (int) $group['course_count'] . ' corsi' ?>
                                · dal <?= date('d/m/Y', strtotime((string) $group['joined_at'])) ?>
                            </span>
                        </span>
                        <?php if ($canManageGroups): ?>
                            <form action="/admin/users/<?= (int) $user['id'] ?>/groups/<?= (int) $group['id'] ?>/delete"
                                  method="post"
                                  onsubmit="return confirm('Togliere l’utente dal gruppo? Le iscrizioni ai corsi restano attive.');">
                                <?= Csrf::field() ?>
                                <button type="submit" class="link-btn">Rimuovi</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($canManageGroups && $availableGroups !== []): ?>
            <form action="/admin/users/<?= (int) $user['id'] ?>/groups" method="post" class="form form-inline">
                <?= Csrf::field() ?>
                <select name="group_id" aria-label="Gruppo a cui aggiungere l'utente">
                    <?php foreach ($availableGroups as $group): ?>
                        <option value="<?= (int) $group['id'] ?>"><?= htmlspecialchars((string) $group['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-secondary">Aggiungi al gruppo</button>
            </form>
        <?php elseif ($canManageGroups): ?>
            <p class="form-hint">Non ci sono altri gruppi a cui aggiungerlo.</p>
        <?php endif; ?>
    </section>

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
