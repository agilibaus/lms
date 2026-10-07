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

$scheda = '<section class="tutor-benvenuto" aria-labelledby="tutor-benvenuto-titolo">'
    . '<div class="tutor-benvenuto-media">'
    . '<img class="tutor-benvenuto-foto" src="' . $foto . '" alt="">'
    . '<audio controls preload="none" src="/benvenuti/' . $wid . '/audio"'
    . ' aria-label="Benvenuto di ' . $nome . '"'
    . ' data-ascoltato="/benvenuti/' . $wid . '/ascoltato"'
    . ' data-token="' . htmlspecialchars(Csrf::token(), ENT_QUOTES) . '"></audio>'
    . '</div>'
    . '<div class="tutor-benvenuto-testo">'
    . '<h2 id="tutor-benvenuto-titolo">Il benvenuto di ' . $nome . '</h2>'
    . '<p class="tutor-benvenuto-sotto">Tutor del tuo gruppo in questo corso.</p>'
    . '<details class="tutor-benvenuto-trascrizione"><summary>Leggi il testo</summary>'
    . '<p>' . nl2br(htmlspecialchars((string) $welcome['transcript']), false) . '</p>'
    . '</details>'
    . '</div>'
    . '</section>';
?>
<?php if ($welcome['modo'] === 'completo'): ?>
    <?= $scheda ?>
<?php else: ?>
    <details class="tutor-benvenuto-ridotto">
        <summary>
            <img class="tutor-benvenuto-miniatura" src="<?= $foto ?>" alt="">
            <span class="tutor-benvenuto-ridotto-nome">Il benvenuto di <?= $nome ?></span>
            <span class="tutor-benvenuto-riascolta">Riascolta</span>
        </summary>
        <?= $scheda ?>
    </details>
<?php endif; ?>
<script src="/assets/js/benvenuto.js"></script>
