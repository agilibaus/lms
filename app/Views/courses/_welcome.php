<?php

declare(strict_types=1);

use App\Core\Csrf;

/**
 * Il benvenuto del tutor in cima alla pagina del corso (07/10), per lo
 * studente: completo nelle prime visite, poi ridotto a una riga che lo
 * riapre. La riga e' un `details`, quindi si apre senza JavaScript.
 *
 * Lo script serve solo a una cosa: dire al server che l'audio e' stato
 * ascoltato fino in fondo, cosi' dalla visita dopo il benvenuto e' ridotto.
 * Senza, vale il conto delle visite.
 *
 * @var array{id:int, tutor_name:string, transcript:string, modo:string} $welcome
 */

$wid = (int) $welcome['id'];
$nome = htmlspecialchars((string) $welcome['tutor_name']);
$foto = '/benvenuti/' . $wid . '/foto';

$scheda = '<section class="benvenuto" aria-labelledby="benvenuto-titolo">'
    . '<div class="benvenuto-media">'
    . '<img class="benvenuto-foto" src="' . $foto . '" alt="">'
    . '<audio controls preload="none" src="/benvenuti/' . $wid . '/audio"'
    . ' aria-label="Benvenuto di ' . $nome . '"'
    . ' data-ascoltato="/benvenuti/' . $wid . '/ascoltato"'
    . ' data-token="' . htmlspecialchars(Csrf::token(), ENT_QUOTES) . '"></audio>'
    . '</div>'
    . '<div class="benvenuto-testo">'
    . '<h2 id="benvenuto-titolo">Il benvenuto di ' . $nome . '</h2>'
    . '<p class="benvenuto-sotto">Tutor del tuo gruppo in questo corso.</p>'
    . '<details class="benvenuto-trascrizione"><summary>Leggi il testo</summary>'
    . '<p>' . nl2br(htmlspecialchars((string) $welcome['transcript']), false) . '</p>'
    . '</details>'
    . '</div>'
    . '</section>';
?>
<?php if ($welcome['modo'] === 'completo'): ?>
    <?= $scheda ?>
<?php else: ?>
    <details class="benvenuto-ridotto">
        <summary>
            <img class="benvenuto-miniatura" src="<?= $foto ?>" alt="">
            <span class="benvenuto-ridotto-nome">Il benvenuto di <?= $nome ?></span>
            <span class="benvenuto-riascolta">Riascolta</span>
        </summary>
        <?= $scheda ?>
    </details>
<?php endif; ?>
<script src="/assets/js/benvenuto.js"></script>
