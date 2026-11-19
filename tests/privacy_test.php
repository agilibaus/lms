<?php

declare(strict_types=1);

/**
 * Test delle regole della privacy (18/11): `App\Core\Privacy` e la riga
 * fissa nell'email «Il tuo accesso».
 *
 * Esecuzione:  php tests/privacy_test.php
 *
 * Le regole non toccano il database; l'email legge i testi in vigore, quindi
 * serve il database di sviluppo. Le pagine si provano in `tests/privacy.js`.
 *
 * COSA VERIFICA:
 *
 *   1. **Il pulsante della barra**: ogni provenienza ha il suo indirizzo e il
 *      suo testo; una provenienza sconosciuta non porta altrove, e senza
 *      provenienza decide l'accesso.
 *   2. **L'avviso**: solo se c'e' una versione e lo studente non l'ha ricevuta.
 *   3. **Il termine di un mese** e la richiesta scaduta.
 *   4. **Il testo dell'Amministratore**: niente script, immagini, video o
 *      segnaposti H5P; restano titoli, tabelle, collegamenti.
 *   5. **L'email «Il tuo accesso»**: la riga dell'informativa dopo il testo
 *      (quindi dopo il link per entrare) e prima della chiusura; solo agli
 *      studenti; niente riga finche' l'informativa non e' pubblicata.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Core\Mail\Mailer;
use App\Core\Mail\Modelli;
use App\Core\Privacy as P;

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

echo "1. Il pulsante della barra\n";

foreach ([
    ['registrazione', false, '/register', '← Torna alla registrazione'],
    ['accesso', false, '/login', '← Torna all’accesso'],
    ['email', false, '/login', 'Vai all’accesso'],
    ['profilo', true, '/profilo', '← Torna al profilo'],
    ['avviso', true, '/', '← Torna a Pistacchio'],
] as [$da, $collegato, $href, $testo]) {
    check("da={$da}: «{$testo}» verso {$href}", P::ritorno($da, $collegato) === [$href, $testo]);
}

check('provenienza sconosciuta, fuori: all\'accesso', P::ritorno('https://altrove.example', false) === ['/login', 'Vai all’accesso']);
check('provenienza sconosciuta, dentro: a Pistacchio', P::ritorno('//altrove', true) === ['/', '← Torna a Pistacchio']);
check('senza provenienza, fuori: all\'accesso', P::ritorno(null, false)[0] === '/login');
check('il collegamento porta la provenienza', P::link('profilo') === '/privacy?da=profilo');

echo "\n2. L'avviso\n";

check('nessuna versione pubblicata: niente avviso', !P::avvisoDaMostrare(null, null));
check('mai ricevuta, versione 1: avviso', P::avvisoDaMostrare(null, 1));
check('ricevuta la 1, in vigore la 1: niente avviso', !P::avvisoDaMostrare(1, 1));
check('ricevuta la 1, in vigore la 3: avviso', P::avvisoDaMostrare(1, 3));
check('ricevuta la 3 (email partita dopo), in vigore la 3: niente', !P::avvisoDaMostrare(3, 3));

echo "\n3. Il termine\n";

check('richiesta del 16/11: entro il 16/12', P::entro('2026-11-16 10:00:00') === '2026-12-16 10:00:00');
check('richiesta del 31/01: entro il 28/02, non il 3/03', P::entro('2027-01-31 09:00:00') === '2027-02-28 09:00:00', P::entro('2027-01-31 09:00:00'));
check('richiesta del 31/12: entro il 31/01 dell\'anno dopo', P::entro('2026-12-31 08:00:00') === '2027-01-31 08:00:00', P::entro('2026-12-31 08:00:00'));
check('il giorno prima della scadenza non e\' scaduta', !P::scaduta('2026-11-16 10:00:00', strtotime('2026-12-15 10:00:00')));
check('il giorno dopo e\' scaduta', P::scaduta('2026-11-16 10:00:00', strtotime('2026-12-17 10:00:00')));

echo "\n4. Il testo dell'Amministratore\n";

$sporco = '<h1>Titolo</h1><h2>1. Chi</h2><p onclick="x()">Testo <a href="javascript:alert(1)">qui</a> '
    . '<a href="mailto:privacy@example.it">scrivi</a></p><script>alert(1)</script>'
    . '<img src="/x.png"><iframe src="https://www.youtube.com/embed/x"></iframe><div data-h5p="3">H5P</div>'
    . '<table><tr><th>Dati</th></tr><tr><td>Account</td></tr></table>';
$pulito = (string) P::pulisci($sporco);

check('il titolo h2 resta', str_contains($pulito, '<h2>1. Chi</h2>'), $pulito);
check('niente h1 (il titolo lo mette la pagina)', !str_contains($pulito, '<h1'), $pulito);
check('niente script', !str_contains($pulito, 'script') && !str_contains($pulito, 'alert(1)<'), $pulito);
check('niente onclick', !str_contains($pulito, 'onclick'), $pulito);
check('niente javascript: nei collegamenti', !str_contains($pulito, 'javascript:'), $pulito);
check('il collegamento mailto resta', str_contains($pulito, 'href="mailto:privacy@example.it"'), $pulito);
check('niente immagini, video, segnaposti H5P', !str_contains($pulito, '<img') && !str_contains($pulito, '<iframe') && !str_contains($pulito, 'data-h5p'), $pulito);
check('la tabella resta', str_contains($pulito, '<table>') && str_contains($pulito, '<td>Account</td>'), $pulito);
check('un testo vuoto resta vuoto', P::pulisci('  <p> </p> ') === null || trim(strip_tags((string) P::pulisci('<p> </p>'))) === '');

echo "\n5. L'email «Il tuo accesso»\n";

$riga = P::rigaEmail(7);
check('la riga nomina l\'informativa e il suo indirizzo', str_contains($riga, 'Accedendo dichiari di aver preso visione della nostra Informativa sulla privacy:')
    && str_contains($riga, '/privacy?da=email'), $riga);
check('nessuna versione: nessuna riga', P::rigaEmail(null) === '');

$con = Modelli::messaggio('accesso', 'marta@example.it', 'Marta Rossi', [
    '{email}' => 'marta@example.it', '{password}' => 'k7Qm-2pXr', '{link}' => 'https://lms.example/login',
], [], null, $riga)->body;
$posLink = strpos($con, 'https://lms.example/login');
$posRiga = strpos($con, 'Accedendo dichiari');
check('la riga c\'e\'', $posRiga !== false, $con);
check('la riga viene dopo il link per entrare', $posLink !== false && $posRiga > $posLink, $con);
check('e dopo tutto il testo modificabile (l\'ultima frase dell\'email)', $posRiga > (int) strpos($con, 'Al primo accesso'), $con);

$staff = Mailer::invite('tutor@example.it', 'Tutor Prova', 'k7Qm-2pXr', 'https://lms.example/login', false)->body;
check('allo staff niente riga', !str_contains($staff, 'Accedendo dichiari'), $staff);

echo "\n6. «Scarica i tuoi dati»\n";

// Il file a piu' fogli: Excel lo apre solo se fogli, relazioni e tipi sono
// numerati allo stesso modo.
$percorso = App\Core\Xlsx::buildFogli([
    ['nome' => 'Primo', 'intestazione' => ['A'], 'righe' => [['uno']]],
    ['nome' => 'Secondo', 'intestazione' => ['B'], 'righe' => [['=SOMMA(1;2)']]],
]);
$zip = new ZipArchive();
$zip->open((string) $percorso);
$libro = (string) $zip->getFromName('xl/workbook.xml');
$relazioni = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');
$tipi = (string) $zip->getFromName('[Content_Types].xml');
$secondo = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
$zip->close();
@unlink((string) $percorso);
check('due fogli, con il loro nome e il loro numero', str_contains($libro, 'name="Primo" sheetId="1" r:id="rId1"')
    && str_contains($libro, 'name="Secondo" sheetId="2" r:id="rId2"'), $libro);
check('le relazioni: i due fogli, poi gli stili', str_contains($relazioni, 'Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"')
    && str_contains($relazioni, 'Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'), $relazioni);
check('i tipi dichiarano tutti e due i fogli', str_contains($tipi, '/xl/worksheets/sheet2.xml'), $tipi);
check('un testo che comincia con = resta testo, non formula', str_contains($secondo, 't="inlineStr"') && !str_contains($secondo, '<f>'), $secondo);

// Lo studente con piu' risposte ai questionari: il caso in cui i dati
// degli altri sarebbero piu' facili da tirarsi dietro.
$db = App\Core\Database::connection();
$uid = (int) $db->query(
    "SELECT a.user_id FROM quiz_attempt_answers aa JOIN quiz_attempts a ON a.id = aa.attempt_id
       JOIN users u ON u.id = a.user_id WHERE u.role = 'studente'
      GROUP BY a.user_id ORDER BY COUNT(*) DESC LIMIT 1"
)->fetchColumn();

if ($uid === 0) {
    check('nel database c\'e\' uno studente con delle risposte', false);
} else {
    $fogli = App\Models\DatiPersonaliModel::fogli($uid);
    $perNome = [];
    foreach ($fogli as $f) {
        $perNome[$f['nome']] = $f;
    }
    $nomi = array_keys($perNome);
    check('i nomi dei fogli sono diversi e validi per Excel', count($nomi) === count($fogli)
        && array_filter($nomi, static fn ($n) => mb_strlen($n) > 31 || preg_match('/[\[\]:*?\/\\\\]/', $n)) === [],
        implode(' | ', $nomi));

    $conta = static function (string $sql) use ($db, $uid): int {
        // PDO non ripete lo stesso segnaposto con le query preparate native:
        // ogni :u diventa :u1, :u2…
        $n = 0;
        $sql = (string) preg_replace_callback('/:u\\b/', static function () use (&$n) { return ':u' . (++$n); }, $sql);
        $st = $db->prepare($sql);
        $st->execute(array_combine(array_map(static fn ($i) => 'u' . $i, range(1, $n)), array_fill(0, $n, $uid)));
        return (int) $st->fetchColumn();
    };
    check('sei fogli, nell\'ordine deciso', $nomi === ['Profilo', 'Corsi', 'Lezioni e video', 'Questionari', 'Incontri dal vivo', 'Quello che hai scritto'],
        implode(' | ', $nomi));
    check('Questionari: una riga per domanda di ogni suo tentativo, una per tentativo senza domande',
        count($perNome['Questionari']['righe'])
        === $conta('SELECT COUNT(DISTINCT aa.attempt_id, aa.question_id) FROM quiz_attempt_answers aa
                      JOIN quiz_attempts a ON a.id = aa.attempt_id WHERE a.user_id = :u')
          + $conta('SELECT COUNT(*) FROM quiz_attempts a WHERE a.user_id = :u
                      AND NOT EXISTS (SELECT 1 FROM quiz_attempt_answers aa WHERE aa.attempt_id = a.id)'));
    check('Corsi: una riga per ogni suo corso, e solo i suoi', count($perNome['Corsi']['righe'])
        === $conta('SELECT COUNT(DISTINCT course_id) FROM (
                        SELECT course_id FROM enrollments WHERE user_id = :u
                        UNION SELECT course_id FROM enrollment_requests WHERE user_id = :u
                        UNION SELECT course_id FROM payments WHERE user_id = :u
                        UNION SELECT course_id FROM certificates WHERE user_id = :u
                        UNION SELECT m.course_id FROM module_unlock_notifications n
                              JOIN modules m ON m.id = n.module_id WHERE n.user_id = :u) t'));
    check('Lezioni e video: una riga per lezione completata, almeno', count($perNome['Lezioni e video']['righe'])
        >= $conta('SELECT COUNT(*) FROM lesson_progress WHERE user_id = :u'));

    $tutto = json_encode($fogli, JSON_UNESCAPED_UNICODE);
    $utente = $db->query('SELECT email, password_hash, calendar_token FROM users WHERE id = ' . $uid)->fetch();
    check('niente impronta della password', !str_contains((string) $tutto, (string) $utente['password_hash']));
    check('niente token del calendario', $utente['calendar_token'] === null
        || !str_contains((string) $tutto, (string) $utente['calendar_token']));
    $altre = $db->query('SELECT email FROM users WHERE id <> ' . $uid)->fetchAll(PDO::FETCH_COLUMN);
    $trovate = array_values(array_filter($altre, static fn ($e) => str_contains((string) $tutto, '"' . $e . '"')));
    check('nessuna email di altre persone', $trovate === [], implode(', ', $trovate));
}

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;

exit($fail > 0 ? 1 : 0);
