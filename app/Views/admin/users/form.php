<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Core\AvatarImage;
use App\Core\Csrf;

/** @var array|null $user */
/** @var array $tutors */
/** @var string[] $roles */
/** @var array $groups */
/** @var array $availableGroups */
/** @var bool $canManageGroups */

$isEdit = $user !== null;
// L'id dell'utente, 0 quando si sta creando: la vista serve a entrambe le cose,
// e la riga esiste solo nel secondo caso.
$userId = (int) ($user['id'] ?? 0);
$action = $isEdit ? '/admin/users/' . $userId : '/admin/users';
$currentRole = $user['role'] ?? 'studente';
// La foto dal pannello (09/10): solo con `user.manage`, e solo per i tutor,
// che devono averne sempre una (`AvatarImage::obbligatoria()`).
$puoCaricareFoto = Auth::can('user.manage');
$haFoto = !empty($user['avatar_path']);
?>
<div class="page-header">
    <a href="/admin/users" class="back-link">&larr; Utenti</a>
    <h1><?= $isEdit ? 'Modifica utente' : 'Nuovo utente' ?></h1>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<form action="<?= htmlspecialchars($action) ?>" method="post" class="form" data-user-form
      enctype="multipart/form-data">
    <?= Csrf::field() ?>

    <label for="first_name">Nome</label>
    <input type="text" id="first_name" name="first_name" maxlength="100" required
           value="<?= htmlspecialchars((string) ($user['first_name'] ?? '')) ?>">

    <label for="last_name">Cognome</label>
    <input type="text" id="last_name" name="last_name" maxlength="100" required
           value="<?= htmlspecialchars((string) ($user['last_name'] ?? '')) ?>">

    <label for="email">Email</label>
    <input type="email" id="email" name="email" maxlength="190" required
           value="<?= htmlspecialchars((string) ($user['email'] ?? '')) ?>">

    <label for="role">Ruolo</label>
    <select id="role" name="role" data-role-select>
        <?php foreach ($roles as $role): ?>
            <?php /* Il valore inviato resta minuscolo: e' quello che sta in
                     tabella. Cambia solo come lo si legge. */ ?>
            <option value="<?= htmlspecialchars($role) ?>" <?= $currentRole === $role ? 'selected' : '' ?>>
                <?= htmlspecialchars(Auth::roleLabel($role)) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <?php if (count($roles) > 1): ?>
        <div data-tutor-field <?= $currentRole === 'assistente' ? '' : 'hidden' ?>>
            <?php /* Caselle e non una tendina: un assistente puo' affiancare piu'
                     tutor insieme, e una tendina a scelta multipla si usa male. */ ?>
            <fieldset class="checkbox-group">
                <legend>Tutor che affianca (solo per gli assistenti)</legend>
                <?php $scelti = $assistantTutorIds ?? []; ?>
                <?php if ($tutors === []): ?>
                    <p class="form-hint">Non c'è ancora nessun tutor: crealo prima, poi collega l'assistente.</p>
                <?php endif; ?>
                <?php foreach ($tutors as $tutor): ?>
                    <label class="checkbox-label">
                        <input type="checkbox" name="tutor_ids[]" value="<?= (int) $tutor['id'] ?>"
                            <?= in_array((int) $tutor['id'], $scelti, true) ? 'checked' : '' ?>>
                        <?= htmlspecialchars((string) $tutor['full_name']) ?>
                    </label>
                <?php endforeach; ?>
                <p class="form-hint">Vede i report degli studenti dei gruppi di tutti i tutor selezionati.</p>
            </fieldset>
        </div>
    <?php endif; ?>

    <?php if ($puoCaricareFoto): ?>
        <?php /* Nell'HTML il campo c'e' sempre: senza JavaScript chi sceglie
                 «Tutor» deve poter caricare la foto nello stesso invio. Lo
                 script lo nasconde quando il ruolo non e' tutor. */ ?>
        <?php /* La stessa disposizione del riquadro «Immagine» del profilo
                 (`.avatar-editor`): la foto a sinistra, il campo e l'aiuto
                 accanto. */ ?>
        <div data-foto-field>
            <label for="avatar">Foto del profilo (per i tutor)</label>
            <div class="avatar-editor">
                <?php if ($haFoto): ?>
                    <img class="avatar avatar-lg" src="/utenti/<?= $userId ?>/immagine" alt="Foto del profilo attuale">
                <?php endif; ?>
                <div class="avatar-editor-actions">
                    <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp"
                           aria-describedby="avatar-aiuto">
                    <p class="form-hint" id="avatar-aiuto">
                        Obbligatoria per un tutor: la vedono i suoi studenti nella pagina del gruppo e nel
                        benvenuto. JPG, PNG, GIF o WebP fino a <?= (int) (AvatarImage::MAX_BYTES / 1024 / 1024) ?>&nbsp;MB,
                        ritagliata quadrata. Agli altri ruoli non si carica da qui: la scelgono dal profilo.
                    </p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <label class="checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= (int) ($user['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
        Account attivo (un account disattivato non può accedere)
    </label>

    <?php if (!$isEdit): ?>
        <p class="form-hint">
            La password iniziale la genera la piattaforma e la manda per email all'utente:
            non la scegli tu e non la conosce nessun altro. Al primo accesso dovrà
            sceglierne una sua.
        </p>
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
                            <form action="/admin/users/<?= $userId ?>/groups/<?= (int) $group['id'] ?>/delete"
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
            <form action="/admin/users/<?= $userId ?>/groups" method="post" class="form form-inline">
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
        <h2>Password temporanea</h2>
        <p class="form-hint" style="margin-top: 0;">
            La password la genera la piattaforma e la manda per email all'utente: non compare
            qui e non la conosce nessun altro. Le sue sessioni aperte vengono chiuse, e al
            primo accesso dovrà sceglierne una sua.
            Se l'invio dell'email non riesce, la password attuale resta valida.
        </p>
        <form action="/admin/users/<?= $userId ?>/password" method="post" class="form">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-secondary">Genera e invia password temporanea</button>
        </form>
    </section>
<?php endif; ?>

<script>
    document.querySelectorAll('[data-user-form]').forEach(function (form) {
        var select = form.querySelector('[data-role-select]');
        var field = form.querySelector('[data-tutor-field]');
        var foto = form.querySelector('[data-foto-field]');

        if (!select) {
            return;
        }

        // La foto si mostra solo per un tutor; nascondendola si toglie anche
        // il file scelto, che il server per gli altri ruoli rifiuterebbe.
        function aggiornaFoto() {
            if (!foto) {
                return;
            }

            foto.hidden = select.value !== 'tutor';

            if (foto.hidden) {
                foto.querySelector('input[type=file]').value = '';
            }
        }

        aggiornaFoto();

        select.addEventListener('change', function () {
            if (field) {
                field.hidden = select.value !== 'assistente';
            }

            aggiornaFoto();
        });
    });
</script>
