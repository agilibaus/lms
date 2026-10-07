<?php

declare(strict_types=1);

/**
 * Test della lettura del file di importazione utenti.
 *
 * Esecuzione:  php tests/importazione_test.php
 *
 * Non tocca il database e non apre nessuna pagina: `UserImport::leggi()`
 * riceve il contenuto di un file e restituisce un giudizio riga per riga.
 * E' li' apposta — tutta la parte che sbaglia davvero si prova con delle
 * stringhe.
 *
 * COSA VERIFICA, e perche' proprio questo. Un lettore di CSV sbaglia in
 * modo silenzioso: non va in errore, importa cose diverse da quelle
 * scritte nel file. I tre modi, in ordine di frequenza vera:
 *
 *   1. **La codifica.** Excel su Windows produce UTF-8 con il BOM oppure
 *      Windows-1252. Col BOM la prima intestazione si chiama davvero
 *      «\xEF\xBB\xBFemail» e non viene riconosciuta: il file sembra non
 *      avere la colonna email. Col Windows-1252 i nomi accentati arrivano
 *      rotti, e nessuno se ne accorge finche' non chiama Nicolo'.
 *   2. **Il separatore.** Excel in italiano scrive il punto e virgola. Un
 *      lettore che accetta solo la virgola vede una colonna sola.
 *   3. **I duplicati dentro al file.** Due righe con la stessa email: se
 *      non le ferma qui, la seconda la ferma il database dicendo «esiste
 *      gia'», che fa pensare a un utente vecchio invece che a un refuso.
 *
 * Provato che sa diventare rosso: togliendo il taglio del BOM falliscono
 * due controlli, accettando solo la virgola come separatore ne falliscono
 * tre, e togliendo il controllo sui duplicati ne fallisce uno.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\UserImport;

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

/** @param list<array<string, mixed>> $righe */
function errori(array $righe): array
{
    return array_values(array_map(
        static fn (array $r): ?string => $r['errore'],
        $righe
    ));
}

$GRUPPI = ['Classe A', 'Marzo 2027'];

// ---------------------------------------------------------------
// Le codifiche che Excel produce
// ---------------------------------------------------------------

echo PHP_EOL . 'Le codifiche' . PHP_EOL;

$conNome = "email,nome,cognome\nnicolo@x.it,Nicolò,Dall'Acqua\n";

$utf8 = UserImport::leggi($conNome, $GRUPPI);
check('UTF-8 semplice: la riga passa', $utf8['errore'] === null && count(UserImport::buone($utf8['righe'])) === 1);
check(
    'UTF-8 semplice: l\'accento resta',
    ($utf8['righe'][0]['nome'] ?? '') === "Nicolò Dall'Acqua",
    'letto: ' . ($utf8['righe'][0]['nome'] ?? '—')
);

// Il caso che si vede solo in produzione: Excel che aggiunge il BOM.
$conBom = UserImport::leggi("\xEF\xBB\xBF" . $conNome, $GRUPPI);
check(
    'con il BOM davanti la colonna email si riconosce lo stesso',
    $conBom['errore'] === null,
    'errore: ' . (string) $conBom['errore']
);
check(
    'con il BOM il nome non si sporca',
    ($conBom['righe'][0]['nome'] ?? '') === "Nicolò Dall'Acqua",
    'letto: ' . ($conBom['righe'][0]['nome'] ?? '—')
);

// Windows-1252: lo stesso file salvato da Excel «CSV (delimitato dal
// separatore di elenco)». Si costruisce convertendo, non scrivendo i byte
// a mano, cosi' il test dice cosa intende.
$w1252 = (string) mb_convert_encoding($conNome, 'Windows-1252', 'UTF-8');
check('il file di prova in Windows-1252 non è UTF-8 valido', !mb_check_encoding($w1252, 'UTF-8'));

$letto1252 = UserImport::leggi($w1252, $GRUPPI);
check(
    'Windows-1252: l\'accento viene recuperato',
    ($letto1252['righe'][0]['nome'] ?? '') === "Nicolò Dall'Acqua",
    'letto: ' . ($letto1252['righe'][0]['nome'] ?? '—')
);

// ---------------------------------------------------------------
// Il separatore
// ---------------------------------------------------------------

echo PHP_EOL . 'Il separatore' . PHP_EOL;

check('la virgola si riconosce', UserImport::separatore('email,nome,gruppo') === ',');
check('il punto e virgola si riconosce', UserImport::separatore('email;nome;gruppo') === ';');
check('la tabulazione si riconosce', UserImport::separatore("email\tnome\tgruppo") === "\t");
check(
    'una colonna sola non manda in confusione',
    UserImport::separatore('email') === ',',
    'senza separatori si sceglie la virgola e si va avanti'
);

$puntoEVirgola = UserImport::leggi("email;nome;cognome;gruppo\nmario@x.it;Mario;Rossi;Classe A\n", $GRUPPI);
check(
    'un file col punto e virgola si legge per intero',
    $puntoEVirgola['errore'] === null
        && ($puntoEVirgola['righe'][0]['nome'] ?? '') === 'Mario Rossi'
        && ($puntoEVirgola['righe'][0]['gruppo'] ?? '') === 'Classe A',
    'nome: ' . ($puntoEVirgola['righe'][0]['nome'] ?? '—')
        . ', gruppo: ' . ($puntoEVirgola['righe'][0]['gruppo'] ?? '—')
);

// ---------------------------------------------------------------
// Le intestazioni
// ---------------------------------------------------------------

echo PHP_EOL . 'Le intestazioni' . PHP_EOL;

$sinonimi = UserImport::leggi("E-Mail; Nome ; COGNOME ;Classe\nmario@x.it;Mario;Rossi;Classe A\n", $GRUPPI);
check(
    'si riconoscono i sinonimi, le maiuscole e gli spazi di troppo',
    $sinonimi['errore'] === null && count(UserImport::buone($sinonimi['righe'])) === 1,
    'errore: ' . (string) $sinonimi['errore']
);

$senzaEmail = UserImport::leggi("nome;gruppo\nMario;Classe A\n", $GRUPPI);
check(
    'senza la colonna email si rifiuta il file, non le righe',
    $senzaEmail['errore'] !== null && $senzaEmail['righe'] === [],
    'errore: ' . (string) $senzaEmail['errore']
);

$senzaNome = UserImport::leggi("email;gruppo\nmario@x.it;Classe A\n", $GRUPPI);
check('senza le colonne del nome si rifiuta il file', $senzaNome['errore'] !== null);

// Nome e cognome in due colonne, sempre (07/10). Un file con il nome in una
// colonna sola si rifiuta per intero, dicendo che cosa fare: dividerlo
// vorrebbe dire indovinare dove finisce il nome.
$completo = UserImport::leggi("email;nome completo\nmario@x.it;Mario Rossi\n", $GRUPPI);
check(
    'una colonna «nome completo» si rifiuta, e il messaggio dice di separarla',
    $completo['righe'] === [] && str_contains((string) $completo['errore'], 'due colonne separate'),
    'errore: ' . (string) $completo['errore']
);
$soloNome = UserImport::leggi("email;nome\nmario@x.it;Mario Rossi\n", $GRUPPI);
check(
    'una colonna «nome» senza «cognome» si rifiuta',
    $soloNome['righe'] === [] && str_contains((string) $soloNome['errore'], '«cognome»'),
    'errore: ' . (string) $soloNome['errore']
);

// ---------------------------------------------------------------
// Il giudizio riga per riga
// ---------------------------------------------------------------

echo PHP_EOL . 'Le righe' . PHP_EOL;

$misto = UserImport::leggi(
    "email;nome;cognome;gruppo\n"
    . "mario@x.it;Mario;Rossi;Classe A\n"
    . ";Senza;Email;\n"
    . "storta;Email;Storta;\n"
    . "MARIO@x.it;Mario;Rossi;\n"
    . "lucia@x.it;Lucia;Verdi;Inesistente\n"
    . "paolo@x.it;;;\n",
    $GRUPPI
);

$e = errori($misto['righe']);
check('la riga buona passa', $e[0] === null);
check('l\'email mancante si ferma', $e[1] === 'manca l\'email', 'detto: ' . (string) $e[1]);
check('l\'email storta si ferma', $e[2] === 'l\'email non è valida', 'detto: ' . (string) $e[2]);
check(
    'il duplicato nel file si ferma, e dice quale riga ripete',
    $e[3] === 'ripetuta nel file (riga 2)',
    'detto: ' . (string) $e[3]
);
check(
    'il gruppo inesistente si ferma',
    $e[4] === 'il gruppo «Inesistente» non esiste',
    'detto: ' . (string) $e[4]
);
check('il nome mancante si ferma', $e[5] === 'manca il nome', 'detto: ' . (string) $e[5]);

check('buone e scartate insieme fanno il totale',
    count(UserImport::buone($misto['righe'])) + count(UserImport::scartate($misto['righe']))
        === count($misto['righe']));

check(
    'il numero di riga è quello del file, non dell\'elenco',
    ($misto['righe'][0]['numero'] ?? 0) === 2,
    'primo numero: ' . (string) ($misto['righe'][0]['numero'] ?? 0)
);

// Le maiuscole nell'email non fanno due persone diverse: gli indirizzi non
// distinguono, e «Mario@x.it» e «mario@x.it» sono la stessa casella.
check(
    'l\'email si normalizza in minuscolo',
    ($misto['righe'][3]['email'] ?? '') === 'mario@x.it',
    'letta: ' . ($misto['righe'][3]['email'] ?? '—')
);

// Il gruppo scritto con un'altra combinazione di maiuscole e' lo stesso
// gruppo, e viene restituito con il nome vero: quello che finisce scritto
// nel database e' il nome del gruppo, non quello che ha battuto l'utente.
$gruppoCaso = UserImport::leggi("email;nome;cognome;gruppo\nx@x.it;Tizio;Caio;  classe   a \n", $GRUPPI);
check(
    'il gruppo si riconosce a prescindere da maiuscole e spazi',
    ($gruppoCaso['righe'][0]['gruppo'] ?? '') === 'Classe A',
    'letto: ' . ($gruppoCaso['righe'][0]['gruppo'] ?? '—')
);

// ---------------------------------------------------------------
// I limiti
// ---------------------------------------------------------------

echo PHP_EOL . 'I limiti' . PHP_EOL;

check('un file vuoto si rifiuta con una frase', UserImport::leggi('', $GRUPPI)['errore'] !== null);

$troppe = "email;nome;cognome\n" . str_repeat("a@x.it;Tizio;Caio\n", UserImport::MAX_RIGHE + 1);
$esito = UserImport::leggi($troppe, $GRUPPI);
check(
    'oltre il massimo di righe il file si rifiuta, invece di importarne una parte',
    $esito['errore'] !== null && $esito['righe'] === [],
    'errore: ' . (string) $esito['errore']
);

echo PHP_EOL . 'Totale: ' . $ok . ' superati, ' . $fail . ' falliti' . PHP_EOL;
exit($fail > 0 ? 1 : 0);
