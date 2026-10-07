<?php

declare(strict_types=1);

use App\Core\Csrf;

/**
 * Il benvenuto del tutor in cima alla pagina del corso (07/10), per lo
 * studente: completo nelle prime visite, poi ridotto a una riga che lo
 * riapre («Mostra»). La riga e' un `details`, quindi si apre senza
 * JavaScript. Dentro la scheda, sotto il nome, l'email del tutor e il link
 * al gruppo WhatsApp dello studente, se ci sono: nella riga ridotta no, si
 * vedono riaprendola (scelta di Elena).
 *
 * Lo script serve solo a una cosa: dire al server che l'audio e' stato
 * ascoltato fino in fondo, cosi' dalla visita dopo il benvenuto e' ridotto.
 * Senza, vale il conto delle visite.
 *
 * @var array{id:int, tutor_name:string, contact_email:?string, transcript:string,
 *            group_name:string, whatsapp_url:?string, modo:string} $welcome
 */

$wid = (int) $welcome['id'];
$nome = htmlspecialchars((string) $welcome['tutor_name']);
$foto = '/benvenuti/' . $wid . '/foto';

// I contatti: l'email che il tutor ha scritto nel profilo e il link del
// gruppo WhatsApp dello studente. Ciascuno solo se c'e'. Le icone sono
// disegni generici (una busta, un fumetto), non il logo di WhatsApp, che e'
// un marchio. Il link WhatsApp si apre in una scheda nuova: porta fuori da
// Pistacchio, e chi torna ritrova il corso dove l'aveva lasciato.
$contatti = '';
if (!empty($welcome['contact_email'])) {
    $email = htmlspecialchars((string) $welcome['contact_email']);
    $contatti .= '<li><span class="tutor-benvenuto-icona tutor-benvenuto-icona-email" aria-hidden="true"></span>'
        . '<a href="mailto:' . $email . '">' . $email . '</a></li>';
}
if (!empty($welcome['whatsapp_url'])) {
    $contatti .= '<li><span class="tutor-benvenuto-icona tutor-benvenuto-icona-chat" aria-hidden="true"></span>'
        . '<a href="' . htmlspecialchars((string) $welcome['whatsapp_url']) . '" target="_blank" rel="noopener">'
        . 'Entra nel gruppo WhatsApp «' . htmlspecialchars((string) $welcome['group_name']) . '»'
        . '<span class="sr-only"> (si apre in una nuova scheda)</span></a></li>';
}

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
    . ($contatti !== '' ? '<ul class="tutor-benvenuto-contatti" role="list">' . $contatti . '</ul>' : '')
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
            <?php /* «Mostra» da chiuso, «Nascondi» da aperto (Elena, 07/10): le
                     scambia lo stile sullo stato del `details`, quindi anche
                     senza JavaScript. Quella nascosta e' `display: none`, e un
                     lettore di schermo legge solo l'altra. */ ?>
            <span class="tutor-benvenuto-riascolta"><span class="tutor-benvenuto-se-chiuso">Mostra</span><span class="tutor-benvenuto-se-aperto">Nascondi</span></span>
        </summary>
        <?= $scheda ?>
    </details>
<?php endif; ?>
<script src="/assets/js/benvenuto.js"></script>
