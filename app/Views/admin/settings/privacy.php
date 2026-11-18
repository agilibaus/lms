<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Privacy;
use App\Core\Quando;

/**
 * Impostazioni › Privacy (18/11, Alessandro).
 *
 * @var list<array{id: int, full_name: string, email: string, deletion_requested_at: string}> $richieste
 * @var array{id: int, content_html: string, published_at: string, corrected_at: ?string}|null $inVigore
 * @var list<array{id: int, published_at: string}> $precedenti
 * @var int $adesso
 */
?>
<div class="page-header">
    <a href="/admin/settings" class="back-link"><span class="back-link-testo">Impostazioni</span></a>
    <h1>Privacy</h1>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<section class="card">
    <h2>Richieste di cancellazione</h2>
    <?php if ($richieste === []): ?>
        <p class="empty-state">Nessuna richiesta.</p>
    <?php else: ?>
        <p class="form-hint">Chi ha chiesto la cancellazione dal profilo ha già l’accesso chiuso. L’account va cancellato entro un mese dalla richiesta.</p>
        <div class="tabella-schede">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Studente</th>
                        <th scope="col">Chiesta il</th>
                        <th scope="col">Da cancellare entro</th>
                        <th scope="col"><span class="sr-only">Azioni</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($richieste as $r): ?>
                        <tr>
                            <td data-label="Studente">
                                <a href="/admin/users/<?= $r['id'] ?>/edit"><?= htmlspecialchars($r['full_name']) ?></a>
                                <span class="cell-sub"><?= htmlspecialchars($r['email']) ?></span>
                            </td>
                            <td data-label="Chiesta il"><?= htmlspecialchars(Quando::data($r['deletion_requested_at'])) ?></td>
                            <td data-label="Da cancellare entro">
                                <?= htmlspecialchars(Quando::data(Privacy::entro($r['deletion_requested_at']))) ?>
                                <?php if (Privacy::scaduta($r['deletion_requested_at'], $adesso)): ?>
                                    <span class="badge badge-danger">scaduta</span>
                                <?php endif; ?>
                            </td>
                            <td class="row-actions">
                                <form action="/admin/settings/privacy/<?= $r['id'] ?>/cancella" method="post"
                                      onsubmit="return confirm('Cancellare l’account di <?= htmlspecialchars(addslashes($r['full_name']), ENT_QUOTES) ?>? Iscrizioni, progressi, risposte e attestati verranno rimossi, e non si torna indietro.');">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="link-btn link-btn-danger">Cancella l’account</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Informativa</h2>
    <p class="form-hint privacy-versione-riga">
        <?php if ($inVigore !== null): ?>
            <span>Versione in vigore: <strong><?= htmlspecialchars(Quando::data($inVigore['published_at'])) ?></strong></span>
            <a href="<?= Privacy::PAGINA ?>">Vedi la pagina pubblica</a>
        <?php else: ?>
            <span>Non ancora pubblicata: finché non lo è, la registrazione, l’email «Il tuo accesso», la pagina di accesso e il profilo non la nominano.</span>
        <?php endif; ?>
    </p>

    <form action="/admin/settings/privacy" method="post" class="stacked-form lesson-form privacy-form">
        <?= Csrf::field() ?>
        <label for="content_html" class="sr-only">Testo dell’informativa</label>
        <textarea id="content_html" name="content_html" rows="24"><?= htmlspecialchars($inVigore['content_html'] ?? '') ?></textarea>

        <?php if ($inVigore !== null): ?>
            <p class="form-hint">
                «Salva la correzione» cambia il testo della versione in vigore, senza avvisare nessuno: per i refusi.
                «Pubblica una nuova versione» mette la data di oggi e mostra l’avviso agli studenti al prossimo accesso.
            </p>
        <?php else: ?>
            <p class="form-hint">Il titolo della pagina e la data della versione li aggiunge Pistacchio: qui solo il testo, dalla prima sezione.</p>
        <?php endif; ?>

        <div class="privacy-azioni">
            <?php if ($inVigore !== null): ?>
                <button type="submit" name="azione" value="correggi" class="btn btn-secondary">Salva la correzione</button>
                <button type="submit" name="azione" value="pubblica" class="btn btn-primary"
                        onclick="return confirm('Pubblicare una nuova versione? Tutti gli studenti vedranno l’avviso al prossimo accesso.');">Pubblica una nuova versione</button>
            <?php else: ?>
                <button type="submit" name="azione" value="pubblica" class="btn btn-primary">Pubblica l’informativa</button>
            <?php endif; ?>
            <?php /* Rimette il testo salvato, anche nell'editor (annulla.js). */ ?>
            <button type="reset" class="btn btn-secondary">Annulla</button>
        </div>
    </form>
</section>

<?php if ($precedenti !== []): ?>
    <section class="card">
        <h2>Versioni precedenti</h2>
        <ul class="privacy-elenco">
            <?php foreach ($precedenti as $p): ?>
                <li><a href="<?= Privacy::PAGINA ?>?versione=<?= $p['id'] ?>"><?= htmlspecialchars(Quando::data($p['published_at'])) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<script src="/assets/vendor/tinymce/tinymce.min.js"></script>
<script>
    // Lo stesso editor delle lezioni, senza immagini, video ed esercizi H5P:
    // l'informativa e' testo, titoli, elenchi, tabelle e collegamenti. Il
    // salvataggio ripassa comunque dal sanificatore (`Privacy::TAG`).
    tinymce.init({
        selector: '#content_html',
        language: 'it',
        base_url: '/assets/vendor/tinymce',
        license_key: 'gpl',
        promotion: false,
        branding: false,
        menubar: 'edit insert format table',
        plugins: 'advlist autolink autoresize charmap code fullscreen link lists searchreplace table wordcount',
        toolbar: 'undo redo | blocks | bold italic underline | bullist numlist | link table | removeformat code fullscreen',
        block_formats: 'Paragrafo=p; Titolo=h2; Sottotitolo=h3; Sotto-sottotitolo=h4',
        autoresize_bottom_margin: 24,
        autoresize_overflow_padding: 16,
        min_height: 420,
        mobile: {
            menubar: false,
            plugins: 'autolink autoresize lists link',
            toolbar: 'undo redo | blocks | bold italic | bullist numlist | link | removeformat',
        },
        content_css: '/assets/css/style.css',
        body_class: 'lesson-content',
        convert_urls: false,
    });
</script>
