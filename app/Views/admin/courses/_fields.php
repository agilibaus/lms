<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Controllers\Admin\CourseController;
use App\Core\CorsiAPagamento;
use App\Core\TestoBreve;

/**
 * Campi condivisi fra creazione e modifica del corso.
 *
 * @var array|null $course
 */

// Obiettivo e descrizione (11/10) accettano qualche tag HTML scritto a mano,
// e il limite conta solo il testo visibile: niente `maxlength`, che
// conterebbe anche i tag, e oltre il limite il server rifiuta e ripropone il
// testo. Il contatore e l'aiuto sui tag li scrive `TestoBreve::campo()`; lo
// script del contatore e' lo stesso degli altri campi.
?>
<label for="title">Titolo</label>
<input type="text" id="title" name="title" maxlength="200" required
       value="<?= htmlspecialchars((string) ($course['title'] ?? '')) ?>">

<label for="slug">Slug (facoltativo)</label>
<input type="text" id="slug" name="slug" maxlength="220"
       value="<?= htmlspecialchars((string) ($course['slug'] ?? '')) ?>">
<p class="form-hint">Se lo lasci vuoto viene generato dal titolo; se è già in uso viene reso univoco con un suffisso.</p>

<?= TestoBreve::campo('objective', 'Obiettivo del corso', 'objective', CourseController::MAX_OBJECTIVE_CHARS,
    (string) ($course['objective'] ?? ''), 3) ?>

<?= TestoBreve::campo('description', 'Descrizione', 'description', CourseController::MAX_DESCRIPTION_CHARS,
    (string) ($course['description'] ?? ''), 4) ?>
<script src="/assets/js/quiz-open-count.js"></script>

<?php /* Il supervisore non pubblica (30/10): la casella c'e', spenta, con la
         frase; il server la ignora comunque. */ ?>
<?php $nonPubblica = \App\Auth\Auth::hasRole('supervisore'); ?>
<label class="checkbox-label">
    <input type="checkbox" name="is_published" value="1" <?= !empty($course['is_published']) ? 'checked' : '' ?>
           <?= $nonPubblica ? 'disabled aria-describedby="pubblica-admin-hint"' : '' ?>>
    Pubblicato (una bozza resta visibile solo allo staff)
</label>
<?php if ($nonPubblica): ?>
    <p class="form-hint" id="pubblica-admin-hint">Il corso lo pubblica l’admin.</p>
<?php endif; ?>

<?php /* «Tre parole» (21/10): spenta di partenza. Accesa, si chiedono per
         i moduli completati da quel momento in poi (`App\Core\TreParole`). */ ?>
<label class="checkbox-label">
    <input type="checkbox" name="three_words" value="1" aria-describedby="three-words-hint" <?= !empty($course['three_words_since']) ? 'checked' : '' ?>>
    Chiedi tre parole alla fine di ogni modulo
</label>
<p class="form-hint" id="three-words-hint">
    Lo studente scrive le prime tre parole che gli vengono; le leggono il tutor del gruppo e l'amministrazione,
    in «Tre parole». Si chiedono per i moduli completati da quando è attiva.
</p>

<label for="enrollment_mode">Come ci si iscrive</label>
<?php
$mode = $course['enrollment_mode'] ?? 'closed';
$admin = Auth::hasRole('admin');
// «A pagamento» (06/11, `CorsiAPagamento`): la sceglie l'admin a interruttore
// acceso; un corso gia' a pagamento la mostra sempre, o salvare la
// cambierebbe senza che nessuno l'abbia scelta. Chi non e' admin la vede
// spenta e non tocca prezzo e link.
$pagato = $mode === CorsiAPagamento::MODALITA;
$conPagamento = CorsiAPagamento::nelModulo($admin, $mode);
?>
<?php if ($pagato && !$admin): ?>
    <select id="enrollment_mode" disabled aria-describedby="enrollment-mode-hint">
        <option selected>A pagamento — lo studente paga su Stripe ed è iscritto</option>
    </select>
    <p class="form-hint" id="enrollment-mode-hint">
        La modalità «A pagamento», con prezzo e link, la cambia solo l’admin.
    </p>
<?php else: ?>
    <select id="enrollment_mode" name="enrollment_mode">
        <option value="closed" <?= $mode === 'closed' ? 'selected' : '' ?>>
            Chiusa — iscrive solo lo staff, o l'assegnazione a un gruppo
        </option>
        <option value="request" <?= $mode === 'request' ? 'selected' : '' ?>>
            Su richiesta — lo studente chiede, un tutor approva
        </option>
        <option value="open" <?= $mode === 'open' ? 'selected' : '' ?>>
            Aperta — lo studente si iscrive da solo dal catalogo
        </option>
        <?php if ($conPagamento): ?>
            <option value="<?= CorsiAPagamento::MODALITA ?>" <?= $pagato ? 'selected' : '' ?>>
                A pagamento — lo studente paga su Stripe ed è iscritto
            </option>
        <?php endif; ?>
    </select>
    <p class="form-hint">
        <?php if ($conPagamento): ?>
            «Su richiesta», «Aperta» e «A pagamento» mostrano il corso nel catalogo, ma solo se è pubblicato.
        <?php else: ?>
            Le ultime due modalità mostrano il corso nel catalogo, ma solo se è pubblicato.
        <?php endif; ?>
    </p>

    <?php if ($conPagamento): ?>
        <?php /* I tre campi del pagamento (variante A del mockup, 06/11). Li mostra
                 lo script solo con «A pagamento» scelto; senza JavaScript restano
                 visibili, e con un'altra modalita' il server non li legge. */ ?>
        <?php
        $prezzoNelCampo = isset($course['prezzo_testo'])
            ? (string) $course['prezzo_testo']
            : CorsiAPagamento::prezzoNelCampo(isset($course['price_cents']) ? (int) $course['price_cents'] : null);
        ?>
        <div class="campi-pagamento" id="campi-pagamento">
            <label for="price">Prezzo, in euro</label>
            <input type="text" id="price" name="price" inputmode="decimal" autocomplete="off"
                   value="<?= htmlspecialchars($prezzoNelCampo) ?>" aria-describedby="price-hint">
            <p class="form-hint" id="price-hint">Lo stesso importo del link di pagamento su Stripe, prima di eventuali sconti.</p>

            <label for="payment_link_url">Link di pagamento</label>
            <input type="url" id="payment_link_url" name="payment_link_url" autocomplete="off" maxlength="255"
                   value="<?= htmlspecialchars((string) ($course['payment_link_url'] ?? '')) ?>" aria-describedby="payment-link-url-hint">
            <p class="form-hint" id="payment-link-url-hint">Su Stripe, «Link di pagamento», poi «Copia link».</p>

            <label for="payment_link_id">Identificativo del link</label>
            <input type="text" id="payment_link_id" name="payment_link_id" autocomplete="off" maxlength="64"
                   value="<?= htmlspecialchars((string) ($course['payment_link_id'] ?? '')) ?>" aria-describedby="payment-link-id-hint">
            <p class="form-hint" id="payment-link-id-hint">Comincia con «plink_»: si legge nella pagina del link su Stripe. Serve a riconoscere il corso quando arriva il pagamento.</p>
        </div>
        <script src="/assets/js/modalita-iscrizione.js"></script>
    <?php endif; ?>
<?php endif; ?>
