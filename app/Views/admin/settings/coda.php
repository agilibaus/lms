<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Icona;
use App\Core\Mail\CodaEmail;

/**
 * «Email in coda e non partite» (30/10, Alessandro): la pagina a se' a cui
 * porta il collegamento in fondo a «Posta» (forma 2), con le due tabelle
 * della variante A. «Rimuovi» chiede conferma: un'email rimossa non parte
 * piu'.
 *
 * @var array{non_partite: list<array<string, mixed>>, in_coda: list<array<string, mixed>>} $elenco
 * @var string|null $ultimoGiro
 */

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$quando = static function (string $data): string {
    $t = strtotime($data);

    return $t === false ? $data : date('d/m H:i', $t);
};
$giro = static function (?string $data): string {
    $t = $data === null ? false : strtotime($data);

    if ($t === false) {
        return 'Il giro della posta non è ancora partito: bin/avvisi va pianificato sul server, ogni quarto d’ora.';
    }

    $giorno = date('Y-m-d', $t);
    $come = match ($giorno) {
        date('Y-m-d') => 'oggi',
        date('Y-m-d', strtotime('-1 day')) => 'ieri',
        default => 'il ' . date('d/m', $t),
    };

    return 'Ultimo giro: ' . $come . ' alle ' . date('H:i', $t) . '.';
};
$tentativi = static fn (int $n): string => $n === 0 ? '—' : ($n === 1 ? '1 tentativo' : $n . ' tentativi');

$tabella = static function (array $righe, bool $finite) use ($e, $quando, $tentativi): string {
    $html = '<div class="tabella-larga coda-email-tabella"><table class="data-table coda-email"><thead><tr>'
        . '<th scope="col">Quando</th><th scope="col">A chi</th><th scope="col">Oggetto</th>'
        . '<th scope="col">Tentativi</th><th scope="col">Ultimo errore</th>'
        . '<th scope="col"><span class="sr-only">Comandi</span></th></tr></thead><tbody>';

    foreach ($righe as $r) {
        $id = (int) $r['id'];
        $html .= '<tr>'
            . '<td class="coda-email-quando">' . $e($quando((string) $r['created_at'])) . '</td>'
            . '<td>' . $e((string) $r['to_email']) . '</td>'
            . '<td>' . $e((string) $r['subject']) . '</td>'
            . '<td class="coda-email-tentativi">' . $e($finite ? CodaEmail::ORE_DI_TENTATIVI . ' ore di tentativi' : $tentativi((int) $r['attempts'])) . '</td>'
            . '<td>' . $e((string) ($r['last_error'] ?? '')) . '</td>'
            . '<td><div class="coda-email-comandi">'
            . '<form action="/admin/settings/posta/coda/' . $id . '/riprova" method="post">' . Csrf::field()
            . '<button type="submit" class="link-btn comando-con-icona">' . Icona::svg('send') . 'Riprova</button></form>'
            . '<form action="/admin/settings/posta/coda/' . $id . '/rimuovi" method="post"'
            . ' onsubmit="return confirm(\'Rimuovere questa email? Non partirà più.\');">' . Csrf::field()
            . '<button type="submit" class="link-btn link-btn-danger comando-con-icona">' . Icona::svg('trash3') . 'Rimuovi</button></form>'
            . '</div></td></tr>';
    }

    return $html . '</tbody></table></div>';
};
?>
<div class="page-header">
    <a href="/admin/settings/posta" class="back-link"><span class="back-link-testo">Server di posta elettronica</span></a>
    <h1>Email in coda e non partite</h1>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<section class="card">
    <p class="card-meta">
        Le email a più persone partono dal giro della posta (<code>bin/avvisi</code>), le altre subito; quelle che
        non partono si ritentano a ogni giro per <?= CodaEmail::ORE_DI_TENTATIVI ?> ore. <?= $e($giro($ultimoGiro)) ?>
    </p>

    <?php if ($elenco['non_partite'] === [] && $elenco['in_coda'] === []): ?>
        <p class="empty-state">Nessuna email in coda o non partita.</p>
    <?php else: ?>
        <?php if ($elenco['non_partite'] !== []): ?>
            <h2 class="coda-email-titolo">Non partite <span class="badge badge-danger"><?= count($elenco['non_partite']) ?></span></h2>
            <?= $tabella($elenco['non_partite'], true) ?>
        <?php endif; ?>

        <?php if ($elenco['in_coda'] !== []): ?>
            <h2 class="coda-email-titolo coda-email-gruppo">In coda <span class="badge badge-muted"><?= count($elenco['in_coda']) ?></span></h2>
            <?= $tabella($elenco['in_coda'], false) ?>
        <?php endif; ?>

        <form action="/admin/settings/posta/coda/riprova-tutte" method="post" class="coda-email-tutte">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-secondary btn-con-icona"><?= Icona::svg('send') ?>Riprova tutte</button>
        </form>
    <?php endif; ?>
</section>
