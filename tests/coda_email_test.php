<?php

declare(strict_types=1);

/**
 * Test della coda delle email (30/10, strada C):
 *
 *   - un'email in coda parte al giro (`CodaEmail::svuota`) e la riga si
 *     cancella solo a invio riuscito; se non parte resta, con il tentativo
 *     e l'errore, e si ritenta al giro dopo;
 *   - passate le 24 ore resta «non partita» e il giro non la tocca piu';
 *     «Riprova» le ridà 24 ore, «Rimuovi» la toglie;
 *   - la stessa chiave non si rimette in coda finche' e' in coda (gli
 *     inviti di un incontro ripremuti);
 *   - un'email presa da un altro giro non si manda due volte;
 *   - gli allegati (il .ics degli inviti) arrivano uguali;
 *   - «subito, con la coda come riserva»: partita, nessuna riga; non
 *     partita, una riga con un tentativo e l'errore.
 *
 * Esecuzione:  php tests/coda_email_test.php
 *
 * ATTENZIONE: scrive davvero nel database del .env (quello di sviluppo).
 * Le righe che crea hanno indirizzi @prova-coda.invalid e si cancellano
 * alla fine, anche se un'asserzione fallisce. Le email non partono: le
 * raccoglie un trasporto finto, che a comando fallisce.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Core\Database;
use App\Core\Mail\CodaEmail;
use App\Core\Mail\MailException;
use App\Core\Mail\Mailer;
use App\Core\Mail\Message;
use App\Core\Mail\Transport;

const DOMINIO = '@prova-coda.invalid';

$ok = 0;
$fail = 0;

function check(string $label, bool $condition, string $dettaglio = ''): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;

    if (!$condition && $dettaglio !== '') {
        echo '         · ' . $dettaglio . PHP_EOL;
    }
}

try {
    $pdo = Database::connection();
} catch (Throwable $e) {
    echo 'Database non raggiungibile: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

$trasporto = new class implements Transport {
    /** @var list<Message> */
    public array $messaggi = [];
    public bool $guasto = false;

    public function send(Message $message): void
    {
        if ($this->guasto) {
            throw new MailException('Il server di posta non risponde (prova).');
        }

        $this->messaggi[] = $message;
    }
};

$pulisci = static function () use ($pdo): void {
    $pdo->exec("DELETE FROM email_queue WHERE to_email LIKE '%" . DOMINIO . "'");
};

// Le righe di questa prova, nell'ordine in cui sono entrate.
$righe = static function () use ($pdo): array {
    return $pdo->query("SELECT * FROM email_queue WHERE to_email LIKE '%" . DOMINIO . "' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
};

// Il giro, ma solo sulle righe di questa prova: le altre della coda di
// sviluppo non si toccano.
$giro = static function () use ($pdo): array {
    $altre = $pdo->query("SELECT id, locked_until FROM email_queue WHERE to_email NOT LIKE '%" . DOMINIO . "'")->fetchAll(PDO::FETCH_ASSOC);
    $pdo->exec("UPDATE email_queue SET locked_until = NOW() + INTERVAL 1 DAY WHERE to_email NOT LIKE '%" . DOMINIO . "'");

    try {
        return CodaEmail::svuota();
    } finally {
        $rimetti = $pdo->prepare('UPDATE email_queue SET locked_until = :l WHERE id = :id');
        foreach ($altre as $a) {
            $rimetti->execute(['l' => $a['locked_until'], 'id' => $a['id']]);
        }
    }
};

$messaggio = static fn (string $chi, string $oggetto = 'Prova della coda', array $allegati = []): Message =>
    new Message($chi . DOMINIO, 'Persona di prova', $oggetto, "Corpo dell'email di prova.", $allegati);

$pulisci();
Mailer::setTransport($trasporto);

try {
    // --- in coda, e il giro la manda -------------------------------------
    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n";
    $esito = CodaEmail::aggiungi($messaggio('anna', 'Con allegato', [['filename' => 'invito.ics', 'mimeType' => 'text/calendar; charset=UTF-8; method=REQUEST', 'content' => $ics]]));
    $r = $righe();
    check('in coda: una riga, nessun tentativo', $esito === CodaEmail::IN_CODA && count($r) === 1 && (int) $r[0]['attempts'] === 0, json_encode($r));
    check('e nell\'elenco «in coda» del pannello', in_array('anna' . DOMINIO, array_column(CodaEmail::elenco()['in_coda'], 'to_email'), true));

    $g = $giro();
    $arrivato = $trasporto->messaggi[0] ?? null;
    check('il giro la manda e la riga si cancella', $g['mandate'] === 1 && $righe() === [] && $arrivato !== null, json_encode($g));
    check('arriva uguale, allegato compreso', $arrivato !== null && $arrivato->toEmail === 'anna' . DOMINIO
        && $arrivato->subject === 'Con allegato' && $arrivato->body === "Corpo dell'email di prova."
        && ($arrivato->attachments[0]['content'] ?? '') === $ics && ($arrivato->attachments[0]['filename'] ?? '') === 'invito.ics');

    // --- non parte: resta, con il tentativo e l'errore -------------------
    $trasporto->messaggi = [];
    $trasporto->guasto = true;
    CodaEmail::aggiungi($messaggio('bruno'));
    $g = $giro();
    $r = $righe();
    check('non parte: la riga resta, con un tentativo e l\'errore', $g['mandate'] === 0 && count($r) === 1
        && (int) $r[0]['attempts'] === 1 && $r[0]['last_error'] === 'Il server di posta non risponde (prova).' && $r[0]['locked_until'] === null, json_encode($r));
    $giro();
    check('e si ritenta al giro dopo', (int) $righe()[0]['attempts'] === 2);

    // --- passate le 24 ore: «non partita», il giro non la tocca ---------
    $pdo->exec("UPDATE email_queue SET retry_until = NOW() - INTERVAL 1 MINUTE WHERE to_email = 'bruno" . DOMINIO . "'");
    $giro();
    $elenco = CodaEmail::elenco();
    check('passate le 24 ore: nell\'elenco «non partite», e il giro non la ritenta',
        (int) $righe()[0]['attempts'] === 2 && in_array('bruno' . DOMINIO, array_column($elenco['non_partite'], 'to_email'), true)
        && !in_array('bruno' . DOMINIO, array_column($elenco['in_coda'], 'to_email'), true));

    // --- «Riprova» e «Riprova tutte» ------------------------------------
    $id = (int) $righe()[0]['id'];
    $trasporto->guasto = false;
    check('«Riprova»: torna in coda per altre 24 ore', CodaEmail::riprova($id)
        && (int) $pdo->query('SELECT TIMESTAMPDIFF(MINUTE, NOW(), retry_until) FROM email_queue WHERE id = ' . $id)->fetchColumn() >= 24 * 60 - 1);
    $giro();
    check('e al giro parte', $righe() === [] && count($trasporto->messaggi) === 1);

    CodaEmail::aggiungi($messaggio('carla'));
    CodaEmail::aggiungi($messaggio('dario'));
    $pdo->exec("UPDATE email_queue SET retry_until = NOW() - INTERVAL 1 MINUTE WHERE to_email LIKE '%" . DOMINIO . "'");
    CodaEmail::riprovaTutte();
    $finite = (int) $pdo->query("SELECT COUNT(*) FROM email_queue WHERE to_email LIKE '%" . DOMINIO . "' AND retry_until <= NOW()")->fetchColumn();
    check('«Riprova tutte»: nessuna resta «non partita»', $finite === 0 && count($righe()) === 2);

    // --- «Rimuovi» --------------------------------------------------------
    $id = (int) $righe()[0]['id'];
    check('«Rimuovi»: la riga se ne va, e l\'email non parte piu\'', CodaEmail::rimuovi($id) && count($righe()) === 1 && !CodaEmail::rimuovi($id));
    $pulisci();

    // --- la stessa chiave non si rimette in coda ------------------------
    $prima = CodaEmail::aggiungi($messaggio('elena', 'Invito'), 'incontro-invito:999:elena' . DOMINIO);
    $seconda = CodaEmail::aggiungi($messaggio('elena', 'Invito'), 'incontro-invito:999:elena' . DOMINIO);
    $altra = CodaEmail::aggiungi($messaggio('franco', 'Invito'), 'incontro-invito:999:franco' . DOMINIO);
    check('la stessa chiave, ancora in coda: non si rimette', $prima === CodaEmail::IN_CODA && $seconda === CodaEmail::GIA_IN_CODA
        && $altra === CodaEmail::IN_CODA && count($righe()) === 2);
    $pdo->exec("UPDATE email_queue SET retry_until = NOW() - INTERVAL 1 MINUTE WHERE to_email = 'elena" . DOMINIO . "'");
    check('controprova: «non partita», la stessa chiave si rimette', CodaEmail::aggiungi($messaggio('elena', 'Invito'), 'incontro-invito:999:elena' . DOMINIO) === CodaEmail::IN_CODA);
    $pulisci();

    // --- presa da un altro giro: non parte due volte --------------------
    $trasporto->messaggi = [];
    CodaEmail::aggiungi($messaggio('gino'));
    $pdo->exec("UPDATE email_queue SET locked_until = NOW() + INTERVAL 5 MINUTE WHERE to_email = 'gino" . DOMINIO . "'");
    $giro();
    check('un\'email presa da un altro giro non si manda', $trasporto->messaggi === [] && count($righe()) === 1);
    $pdo->exec("UPDATE email_queue SET locked_until = NOW() - INTERVAL 1 MINUTE WHERE to_email = 'gino" . DOMINIO . "'");
    $giro();
    check('controprova: scaduta la presa, parte', count($trasporto->messaggi) === 1 && $righe() === []);

    // --- subito, con la coda come riserva -------------------------------
    $trasporto->messaggi = [];
    $partita = Mailer::subitoOInCoda($messaggio('ivo', 'Password cambiata'));
    check('subito: partita, e niente in coda', $partita && count($trasporto->messaggi) === 1 && $righe() === []);
    $trasporto->guasto = true;
    $partita = Mailer::subitoOInCoda($messaggio('ivo', 'Password cambiata'));
    $r = $righe();
    check('subito, non partita: in coda con un tentativo e l\'errore, e la pagina va avanti', !$partita && count($r) === 1
        && (int) $r[0]['attempts'] === 1 && $r[0]['last_error'] === 'Il server di posta non risponde (prova).'
        && $r[0]['last_attempt_at'] !== null, json_encode($r));
} finally {
    Mailer::setTransport(null);
    $pulisci();
}

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;

exit($fail > 0 ? 1 : 0);
