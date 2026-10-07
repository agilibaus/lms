<?php

declare(strict_types=1);

/**
 * Test della coda degli inviti: chi ha una password che conosce non deve
 * vedersela sovrascrivere.
 *
 * Esecuzione:  php tests/inviti_test.php
 *
 * IL DIFETTO (07/10, riprodotto prima di correggerlo). Il comando degli
 * inviti scrive una password nuova a chi e' in coda e manda l'email; se
 * l'email fallisce l'utente resta in coda e al giro dopo riceve un'altra
 * password. Questo era innocuo solo se chi e' in coda non conosceva nessuna
 * password — e non era garantito: una password temporanea data dal
 * pannello, un recupero via email o un'email consegnata nonostante l'errore
 * lasciavano l'utente in coda con una password in mano, e il giro dopo
 * gliela sostituiva. Credenziali giuste, salvate nel browser, respinte.
 *
 * Usa il database dell'installazione, come `password_test.php`, con un
 * utente di prova a un id alto, cancellato alla fine. **La prova completa,
 * con il comando vero, parte solo se in coda non c'e' nessun altro**: il
 * comando prende tutti gli utenti in coda, e su un database con studenti
 * veri in attesa scriverebbe le loro password. In quel caso lo dice e la
 * salta. La posta e' sostituita da un trasporto finto per tutto il test.
 */

// L'accesso rigenera l'id di sessione, che PHP rifiuta di fare dopo che
// qualcosa e' gia' stato stampato: si trattiene l'uscita e la si manda via
// via, con la sessione aperta prima di qualunque riga.
ob_start();
session_start();

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Invites;
use App\Core\Mail\MailException;
use App\Core\Mail\Mailer;
use App\Core\Mail\Message;
use App\Core\Mail\Transport;
use App\Models\UserModel;

$ok = 0;
$fail = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
    if (!$condition && $detail !== '') {
        echo '         · ' . $detail . PHP_EOL;
    }
}

/** Una posta che rifiuta tutto, o che tiene da parte quello che riceve. */
final class PostaDiProva implements Transport
{
    /** @var list<Message> */
    public array $mandati = [];

    public function __construct(private bool $funziona)
    {
    }

    public function send(Message $message): void
    {
        if (!$this->funziona) {
            throw new MailException('posta di prova: non risponde');
        }
        $this->mandati[] = $message;
    }
}

$db = Database::connection();
$id = 999124;
$email = 'prova-inviti-' . $id . '@example.invalid';

$inCoda = static function () use ($db, $id): bool {
    $stmt = $db->prepare('SELECT invite_pending FROM users WHERE id = :id');
    $stmt->execute(['id' => $id]);

    return (int) $stmt->fetchColumn() === 1;
};
$vale = static function (string $password) use ($db, $id): bool {
    $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = :id');
    $stmt->execute(['id' => $id]);

    return password_verify($password, (string) $stmt->fetchColumn());
};
$mettiInCoda = static function () use ($db, $id): void {
    $db->prepare('UPDATE users SET invite_pending = 1 WHERE id = :id')->execute(['id' => $id]);
};

$db->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
$db->prepare(
    'INSERT INTO users (id, email, password_hash, full_name, role, email_verified_at, invite_pending)
     VALUES (:id, :email, :hash, :nome, "studente", NOW(), 1)'
)->execute([
    'id' => $id,
    'email' => $email,
    'hash' => password_hash('NessunoLaSa9', PASSWORD_DEFAULT),
    'nome' => 'Utente di prova degli inviti',
]);

// L'ultimo giro registrato si rimette com'era alla fine: e' un'impostazione
// dell'installazione, e il test non deve cambiarla (§5, «Prove»).
$ultimoGiro = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'INVITES_LAST_RUN_AT'")->fetchColumn();


try {
    echo PHP_EOL . 'Chi ottiene una password per un\'altra strada esce dalla coda' . PHP_EOL;

    UserModel::updatePassword($id, 'TemporaneaDalPannello2', true);
    check('password temporanea dal pannello: fuori dalla coda', !$inCoda());

    $mettiInCoda();
    UserModel::updatePassword($id, 'SceltaDaLui3');
    check('cambio dal profilo o recupero via email: fuori dalla coda', !$inCoda());

    echo PHP_EOL . 'Chi fa accesso esce dalla coda' . PHP_EOL;

    $mettiInCoda();
    check('l\'accesso con la password giusta riesce', Auth::attempt($email, 'SceltaDaLui3'));
    check('e lo toglie dalla coda', !$inCoda());

    $mettiInCoda();
    Auth::attempt($email, 'PasswordSbagliata4');
    check('un accesso fallito non lo toglie dalla coda', $inCoda(),
        'chi sbaglia la password non ha dimostrato di averne una');

    echo PHP_EOL . 'La scrittura dell\'invito non tocca chi e\' fuori dalla coda' . PHP_EOL;

    $db->prepare('UPDATE users SET invite_pending = 0 WHERE id = :id')->execute(['id' => $id]);
    $scritta = UserModel::setInvitePassword($id, 'DaInvito5');
    check('fuori dalla coda: la password dell\'invito non si scrive', !$scritta);
    check('e quella che aveva resta valida', $vale('SceltaDaLui3'));

    $mettiInCoda();
    check('in coda: la password dell\'invito si scrive', UserModel::setInvitePassword($id, 'DaInvito6'));

    echo PHP_EOL . 'Il comando vero, con la posta che non risponde' . PHP_EOL;

    $altri = (int) $db->query('SELECT COUNT(*) FROM users WHERE invite_pending = 1 AND id <> ' . $id)->fetchColumn();

    if ($altri > 0) {
        echo '  --   in coda ci sono ' . $altri . ' utenti veri: il comando li toccherebbe, prova saltata' . PHP_EOL;
    } else {
        // IL CASO DEL DIFETTO: una password data dal pannello mentre era in
        // coda, e com'era prima della correzione nessuno lo toglieva dalla
        // coda; poi l'utente fa accesso con quella password. Prima della
        // correzione il giro gliela sostituiva.
        UserModel::updatePassword($id, 'TemporaneaDalPannello7', true);
        $mettiInCoda();
        Auth::attempt($email, 'TemporaneaDalPannello7');

        Mailer::setTransport(new PostaDiProva(false));
        $esito = Invites::mandaScaglione();
        check('chi ha fatto accesso non riceve una password nuova', $vale('TemporaneaDalPannello7'),
            'la password con cui e\' entrato non vale piu\': e\' il difetto del 07/10');
        check('e non risulta fra gli inviti non partiti', !in_array($email, $esito['falliti'], true));

        // Il percorso normale resta quello di prima: chi non ha mai avuto una
        // password, con la posta che non risponde, resta in coda...
        $db->prepare('UPDATE users SET invite_pending = 1, password_hash = :h WHERE id = :id')
            ->execute(['h' => password_hash('NessunoLaSa9', PASSWORD_DEFAULT), 'id' => $id]);
        $esito = Invites::mandaScaglione();
        check('posta che non risponde: resta in coda e riproverà', $inCoda() && in_array($email, $esito['falliti'], true));

        // ...e con la posta che funziona riceve una password che vale.
        $posta = new PostaDiProva(true);
        Mailer::setTransport($posta);
        $esito = Invites::mandaScaglione();
        $mandato = $posta->mandati[0] ?? null;
        $passwordMandata = null;
        if ($mandato !== null) {
            foreach (preg_split('/\s+/', $mandato->body) ?: [] as $parola) {
                if ($parola !== '' && $vale($parola)) {
                    $passwordMandata = $parola;
                }
            }
        }
        check('posta che funziona: l\'invito parte e lo toglie dalla coda', $esito['mandati'] === 1 && !$inCoda());
        check('e la password scritta nell\'email è quella che vale', $passwordMandata !== null);
    }
} finally {
    Mailer::setTransport(null);
    $db->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
    if ($ultimoGiro === false) {
        $db->exec("DELETE FROM settings WHERE setting_key = 'INVITES_LAST_RUN_AT'");
    } else {
        $db->prepare("UPDATE settings SET setting_value = :v WHERE setting_key = 'INVITES_LAST_RUN_AT'")
            ->execute(['v' => $ultimoGiro]);
    }
}

echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
