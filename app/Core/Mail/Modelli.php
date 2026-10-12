<?php

declare(strict_types=1);

namespace App\Core\Mail;

use App\Core\Database;
use App\Core\Settings;

/**
 * Le email della piattaforma, in un elenco solo (12/10, Alessandro).
 *
 * Nessun testo e' scritto nel codice delle pagine: ogni email e' una voce di
 * `elenco()`, con il testo predefinito, i segnaposto che puo' usare e quelli
 * che non possono mancare. Da qui escono sia i messaggi sia la pagina del
 * pannello Impostazioni › Messaggi email, che si costruisce da sola: un'email nuova e'
 * una voce in piu'.
 *
 * LE REGOLE
 *   - Oggetto e testo stanno in `settings`; **un campo vuoto vale il testo
 *     predefinito**, come per gli incontri dal vivo (le cui chiavi `LIVE_*`
 *     restano quelle di prima, con i testi gia' salvati).
 *   - **Segnaposto comuni** a tutte: `{nome}` (il solo nome di chi riceve),
 *     `{organizzazione}`, `{piattaforma}`, `{contatto}`.
 *   - **«Pistacchio LMS» e' il nome della piattaforma, fisso**: `{piattaforma}`
 *     vale sempre quello, e non si cambia da nessuna parte. Il nome
 *     dell'organizzazione invece si imposta dal pannello: nel codice non c'e'
 *     (un test lo controlla).
 *   - In fondo a ogni email si aggiunge la **chiusura**, una per tutte.
 *   - Un segnaposto sconosciuto resta scritto com'e': un errore di battitura
 *     si vede nella prova invece di sparire. Il pannello lo segnala.
 *   - **I segnaposto obbligatori** non possono mancare nel testo salvato: un
 *     invito senza `{password}` lascerebbe una persona senza modo di entrare.
 *   - I testi sono senza genere: la piattaforma non sa se chi legge e' donna
 *     o uomo (come il saluto dopo l'accesso).
 */
final class Modelli
{
    /** Il nome della piattaforma: fisso, non modificabile (Alessandro, 12/10). */
    public const PIATTAFORMA = 'Pistacchio LMS';

    /** Quanto puo' essere lungo il testo di un'email, segnaposto compresi. */
    public const MAX_TESTO = 1000;

    /** Quanto puo' essere lunga la chiusura comune. */
    public const MAX_CHIUSURA = 500;

    /** Le chiavi dei dati comuni, nella pagina Messaggi email. */
    public const ORGANIZZAZIONE = 'ORGANIZZAZIONE_NOME';
    public const RISPONDI_A = 'MAIL_REPLY_TO';
    public const CHIUSURA = 'EMAIL_CHIUSURA';

    public const CHIUSURA_PREDEFINITA = "A presto,\n{organizzazione}\n\n"
        . "Messaggio automatico di {piattaforma}: per qualsiasi domanda scrivi a {contatto}.";

    /** I gruppi della pagina, nell'ordine in cui compaiono. */
    public const GRUPPI = [
        'account' => 'Account e accesso',
        'iscrizioni' => 'Iscrizioni',
        'corso' => 'Corso',
        'incontri' => 'Incontri dal vivo',
    ];

    /** @var array<string, string> nome di battesimo per indirizzo, letto una volta */
    private static array $nomi = [];

    /**
     * I segnaposto comuni a tutte le email, con la spiegazione del pannello.
     *
     * @return array<string, string>
     */
    public static function segnapostoComuni(): array
    {
        return [
            '{nome}' => 'il nome di chi riceve l’email (solo il nome, non il cognome)',
            '{organizzazione}' => 'il nome dell’organizzazione, impostato qui sopra',
            '{piattaforma}' => 'Pistacchio LMS',
            '{contatto}' => 'l’indirizzo «Rispondi a» impostato qui sopra',
        ];
    }

    /**
     * L'elenco delle email.
     *
     * @return array<string, array{gruppo: string, titolo: string, quando: string, oggetto: string, testo: string,
     *     predefinito: array{0: string, 1: string}, segnaposto: array<string, string>, obbligatori: list<string>,
     *     esempio: array<string, string>}>
     */
    public static function elenco(): array
    {
        $link = '{link}';
        $esempioLink = 'https://esempio.invalid/…';

        $incontri = LiveSessionMail::placeholders();
        $incontriSenzaCambio = array_filter(
            $incontri,
            static fn (string $s): bool => !str_ends_with($s, '_precedente}'),
            ARRAY_FILTER_USE_KEY
        );
        $esempioIncontro = [
            '{titolo}' => 'Incontro di metà corso', '{descrizione}' => 'Parleremo delle prime settimane.',
            '{data}' => '18/09/2026', '{ora_inizio}' => '18:30', '{ora_fine}' => '20:00',
            '{link_meet}' => 'https://meet.google.com/abc-defg-hij', '{link_sessione}' => $esempioLink,
            '{corso}' => 'Il corso di esempio', '{modulo}' => 'Primo modulo', '{gruppo}' => 'Gruppo del martedì',
            '{data_precedente}' => '11/09/2026', '{ora_inizio_precedente}' => '17:00', '{ora_fine_precedente}' => '18:30',
        ];

        return [
            'verifica' => [
                'gruppo' => 'account',
                'titolo' => 'Conferma dell’indirizzo email',
                'quando' => 'Alla registrazione dalla pagina pubblica, a chi si registra.',
                'oggetto' => 'EMAIL_VERIFICA_OGGETTO',
                'testo' => 'EMAIL_VERIFICA_TESTO',
                'predefinito' => [
                    'Conferma il tuo indirizzo email',
                    "Ciao {nome},\n\n"
                    . "per completare la registrazione a {piattaforma} di {organizzazione} conferma il tuo "
                    . "indirizzo email aprendo questo link:\n\n"
                    . "{link}\n\n"
                    . "Il link resta valido 24 ore. Se la registrazione non l'hai chiesta tu, ignora questo "
                    . "messaggio: senza conferma l'account non viene attivato.",
                ],
                'segnaposto' => [$link => 'il collegamento che conferma l’indirizzo'],
                'obbligatori' => [$link],
                'esempio' => [$link => $esempioLink],
            ],
            'reimposta' => [
                'gruppo' => 'account',
                'titolo' => 'Reimposta la password',
                'quando' => 'Da «Password dimenticata», a chi la chiede.',
                'oggetto' => 'EMAIL_REIMPOSTA_OGGETTO',
                'testo' => 'EMAIL_REIMPOSTA_TESTO',
                'predefinito' => [
                    'Reimposta la password',
                    "Ciao {nome},\n\n"
                    . "è arrivata una richiesta di reimpostare la password del tuo account su {piattaforma}.\n"
                    . "Puoi farlo da qui:\n\n"
                    . "{link}\n\n"
                    . "Il link vale un'ora e si può usare una volta sola. Se la richiesta non l'hai fatta tu, "
                    . "non serve fare niente: la password attuale resta valida.",
                ],
                'segnaposto' => [$link => 'il collegamento per scegliere la password nuova'],
                'obbligatori' => [$link],
                'esempio' => [$link => $esempioLink],
            ],
            'password_temporanea' => [
                'gruppo' => 'account',
                'titolo' => 'Password temporanea',
                'quando' => 'Quando chi amministra genera una password temporanea per un account che esiste già.',
                'oggetto' => 'EMAIL_TEMPORANEA_OGGETTO',
                'testo' => 'EMAIL_TEMPORANEA_TESTO',
                'predefinito' => [
                    'Una password temporanea per il tuo account',
                    "Ciao {nome},\n\n"
                    . "chi amministra {piattaforma} per {organizzazione} ha generato una password temporanea per "
                    . "il tuo account. La password precedente non funziona più, e le sessioni aperte sono state "
                    . "chiuse.\n\n"
                    . "Password temporanea: {password}\n\n"
                    . "Entra da qui:\n"
                    . "{link}\n\n"
                    . "Al primo accesso ti verrà chiesto di sceglierne una tua.",
                ],
                'segnaposto' => ['{password}' => 'la password temporanea', $link => 'la pagina di accesso'],
                'obbligatori' => ['{password}', $link],
                'esempio' => ['{password}' => 'Esempio2345678', $link => $esempioLink],
            ],
            'accesso' => [
                'gruppo' => 'account',
                'titolo' => 'Il tuo accesso',
                'quando' => 'A chi riceve un account nuovo: creato da chi amministra, o importato da un file '
                    . '(in questo caso parte con gli inviti a scaglioni).',
                'oggetto' => 'EMAIL_ACCESSO_OGGETTO',
                'testo' => 'EMAIL_ACCESSO_TESTO',
                'predefinito' => [
                    'Il tuo accesso a {piattaforma}',
                    "Ciao {nome},\n\n"
                    . "{organizzazione} ha creato per te un account su {piattaforma}, la piattaforma dei suoi "
                    . "corsi online.\n\n"
                    . "Email con cui entrare: {email}\n"
                    . "Password provvisoria: {password}\n\n"
                    . "Entra da qui:\n"
                    . "{link}\n\n"
                    . "Al primo accesso ti verrà chiesto di scegliere una password nuova.",
                ],
                'segnaposto' => [
                    '{email}' => 'l’indirizzo con cui entrare',
                    '{password}' => 'la password provvisoria',
                    $link => 'la pagina di accesso',
                ],
                'obbligatori' => ['{password}', $link],
                'esempio' => ['{email}' => 'nome@esempio.invalid', '{password}' => 'Esempio2345678', $link => $esempioLink],
            ],
            'password_cambiata' => [
                'gruppo' => 'account',
                'titolo' => 'La password è stata cambiata',
                'quando' => 'Quando una persona cambia la propria password: dal profilo, al primo accesso o da «Password dimenticata».',
                'oggetto' => 'EMAIL_CAMBIATA_OGGETTO',
                'testo' => 'EMAIL_CAMBIATA_TESTO',
                'predefinito' => [
                    'La password del tuo account è stata cambiata',
                    "Ciao {nome},\n\n"
                    . "la password del tuo account su {piattaforma} è appena stata cambiata, e le altre sessioni "
                    . "aperte sono state chiuse.\n\n"
                    . "Se il cambio l'hai fatto tu, non serve fare niente.\n\n"
                    . "Altrimenti qualcuno conosce la tua password: reimpostala subito da «Password dimenticata» "
                    . "nella pagina di accesso, e scrivi a {contatto}.\n\n"
                    . "{link}",
                ],
                'segnaposto' => [$link => 'la pagina di accesso'],
                'obbligatori' => [],
                'esempio' => [$link => $esempioLink],
            ],
            'iscrizione_confermata' => [
                'gruppo' => 'iscrizioni',
                'titolo' => 'Iscrizione confermata',
                'quando' => 'A chi si iscrive da solo dal catalogo o vede approvata la sua richiesta, subito. A chi viene iscritto '
                    . 'dallo staff, a mano o con un gruppo, entro un quarto d’ora (a scaglioni). Non a chi aspetta ancora l’invito.',
                'oggetto' => 'EMAIL_ISCRIZIONE_OGGETTO',
                'testo' => 'EMAIL_ISCRIZIONE_TESTO',
                'predefinito' => [
                    'Iscrizione confermata: {corso}',
                    "Ciao {nome},\n\n"
                    . "la tua iscrizione al corso «{corso}» è confermata. Puoi iniziare da qui:\n\n"
                    . "{link}",
                ],
                'segnaposto' => ['{corso}' => 'il titolo del corso', $link => 'la pagina del corso'],
                'obbligatori' => [$link],
                'esempio' => ['{corso}' => 'Il corso di esempio', $link => $esempioLink],
            ],
            'richiesta_iscrizione' => [
                'gruppo' => 'iscrizioni',
                'titolo' => 'Richiesta di iscrizione',
                'quando' => 'Quando uno studente chiede di iscriversi a un corso «su richiesta»: agli amministratori e ai tutor '
                    . 'dei gruppi a cui il corso è assegnato, cioè a chi può approvare.',
                'oggetto' => 'EMAIL_RICHIESTA_OGGETTO',
                'testo' => 'EMAIL_RICHIESTA_TESTO',
                'predefinito' => [
                    'Richiesta di iscrizione: {corso}',
                    "Ciao {nome},\n\n"
                    . "{studente} chiede di iscriversi al corso «{corso}».\n\n"
                    . "{messaggio}\n\n"
                    . "Puoi approvare o rifiutare la richiesta qui:\n"
                    . "{link}",
                ],
                'segnaposto' => [
                    '{studente}' => 'nome e cognome di chi chiede',
                    '{corso}' => 'il titolo del corso',
                    '{messaggio}' => 'quello che lo studente ha scritto, se ha scritto qualcosa (preceduto da «Ha scritto:»)',
                    $link => 'la pagina del corso dove si approva o si rifiuta',
                ],
                'obbligatori' => [$link],
                'esempio' => [
                    '{studente}' => 'Anna Rossi', '{corso}' => 'Il corso di esempio',
                    '{messaggio}' => 'Ha scritto: «Vorrei partecipare insieme a mio marito.»', $link => $esempioLink,
                ],
            ],
            'richiesta_rifiutata' => [
                'gruppo' => 'iscrizioni',
                'titolo' => 'Richiesta non accolta',
                'quando' => 'Allo studente, quando la sua richiesta di iscrizione viene rifiutata.',
                'oggetto' => 'EMAIL_RIFIUTATA_OGGETTO',
                'testo' => 'EMAIL_RIFIUTATA_TESTO',
                'predefinito' => [
                    'Richiesta di iscrizione non accolta: {corso}',
                    "Ciao {nome},\n\n"
                    . "la tua richiesta di iscrizione al corso «{corso}» non è stata accolta. Per saperne di più "
                    . "puoi scrivere a {contatto}.",
                ],
                'segnaposto' => ['{corso}' => 'il titolo del corso'],
                'obbligatori' => [],
                'esempio' => ['{corso}' => 'Il corso di esempio'],
            ],
            'nuovo_modulo' => [
                'gruppo' => 'corso',
                'titolo' => 'Nuovo modulo disponibile',
                'quando' => 'Quando si apre un modulo con una data di apertura (comando pianificato): agli studenti attivi iscritti '
                    . 'prima dell’apertura, non a chi ha il modulo ancora chiuso da un questionario.',
                'oggetto' => 'EMAIL_MODULO_OGGETTO',
                'testo' => 'EMAIL_MODULO_TESTO',
                'predefinito' => [
                    'Nuovo modulo disponibile: {modulo}',
                    "Ciao {nome},\n\n"
                    . "nel corso «{corso}» si è aperto il modulo «{modulo}». Puoi cominciarlo da qui:\n\n"
                    . "{link}\n\n"
                    . "Buono studio!",
                ],
                'segnaposto' => ['{corso}' => 'il titolo del corso', '{modulo}' => 'il titolo del modulo', $link => 'il modulo nella pagina del corso'],
                'obbligatori' => [$link],
                'esempio' => ['{corso}' => 'Il corso di esempio', '{modulo}' => 'Secondo modulo', $link => $esempioLink],
            ],
            'corso_completato' => [
                'gruppo' => 'corso',
                'titolo' => 'Corso completato',
                'quando' => 'Quando si emette il certificato di un corso: da solo, a lezioni completate e questionari '
                    . 'superati, o a mano dallo staff.',
                'oggetto' => 'EMAIL_COMPLETATO_OGGETTO',
                'testo' => 'EMAIL_COMPLETATO_TESTO',
                'predefinito' => [
                    'Corso completato: {corso}',
                    "Ciao {nome},\n\n"
                    . "hai completato il corso «{corso}». Complimenti per il percorso fatto!\n\n"
                    . "Il tuo certificato è pronto, puoi scaricarlo da qui:\n"
                    . "{link}",
                ],
                'segnaposto' => ['{corso}' => 'il titolo del corso', $link => 'lo scaricamento del certificato'],
                'obbligatori' => [$link],
                'esempio' => ['{corso}' => 'Il corso di esempio', $link => $esempioLink],
            ],
            'risposta_esperto' => [
                'gruppo' => 'corso',
                'titolo' => 'La tua domanda ha una risposta',
                'quando' => 'Allo studente che ha fatto la domanda, quando l’esperto la pubblica con la risposta.',
                'oggetto' => 'EMAIL_RISPOSTA_OGGETTO',
                'testo' => 'EMAIL_RISPOSTA_TESTO',
                'predefinito' => [
                    'La tua domanda ha una risposta: {corso}',
                    "Ciao {nome},\n\n"
                    . "l'esperto ha risposto alla domanda che hai fatto nel corso «{corso}».\n\n"
                    . "La tua domanda:\n"
                    . "{domanda}\n\n"
                    . "La risposta:\n"
                    . "{risposta}\n\n"
                    . "Domanda e risposta sono ora fra le domande e risposte del corso:\n"
                    . "{link}",
                ],
                'segnaposto' => [
                    '{corso}' => 'il titolo del corso',
                    '{domanda}' => 'la domanda, come è stata pubblicata',
                    '{risposta}' => 'la risposta dell’esperto',
                    $link => 'le domande e risposte del corso',
                ],
                'obbligatori' => [$link],
                'esempio' => [
                    '{corso}' => 'Il corso di esempio',
                    '{domanda}' => 'Come si fa la pratica del mattino se i figli sono già svegli?',
                    '{risposta}' => 'Si può spostare alla sera: conta la regolarità, non l’orario.',
                    $link => $esempioLink,
                ],
            ],
            'nuova_domanda' => [
                'gruppo' => 'corso',
                'titolo' => 'Nuova domanda all’esperto',
                'quando' => 'Agli amministratori, quando uno studente fa una domanda all’esperto.',
                'oggetto' => 'EMAIL_DOMANDA_OGGETTO',
                'testo' => 'EMAIL_DOMANDA_TESTO',
                'predefinito' => [
                    'Nuova domanda: {corso}',
                    "Ciao {nome},\n\n"
                    . "{studente} ha fatto una domanda all'esperto nel corso «{corso}» ({modulo}):\n\n"
                    . "{domanda}\n\n"
                    . "Puoi rispondere e pubblicarla, o scartarla, qui:\n"
                    . "{link}",
                ],
                'segnaposto' => [
                    '{studente}' => 'nome e cognome di chi chiede',
                    '{corso}' => 'il titolo del corso',
                    '{modulo}' => 'il titolo del modulo, o «il corso in generale»',
                    '{domanda}' => 'il testo della domanda',
                    $link => 'la pagina «Domande»',
                ],
                'obbligatori' => [$link],
                'esempio' => [
                    '{studente}' => 'Anna Rossi', '{corso}' => 'Il corso di esempio', '{modulo}' => 'Primo modulo',
                    '{domanda}' => 'Come si fa la pratica del mattino se i figli sono già svegli?', $link => $esempioLink,
                ],
            ],
            'incontro_invito' => [
                'gruppo' => 'incontri',
                'titolo' => 'Invito all’incontro',
                'quando' => 'Quando premi «Invia inviti» nella pagina di un incontro; si può ripetere dopo nuove iscrizioni. '
                    . 'Ha allegato il file per il calendario.',
                'oggetto' => 'LIVE_INVITE_SUBJECT',
                'testo' => 'LIVE_INVITE_BODY',
                'predefinito' => [LiveSessionMail::DEFAULTS['LIVE_INVITE_SUBJECT'], LiveSessionMail::DEFAULTS['LIVE_INVITE_BODY']],
                'segnaposto' => $incontri,
                'obbligatori' => [],
                'esempio' => $esempioIncontro,
            ],
            'incontro_promemoria' => [
                'gruppo' => 'incontri',
                'titolo' => 'Promemoria dell’incontro',
                'quando' => 'Da sola, il giorno dell’incontro, sette ore prima dell’inizio (comando pianificato), '
                    . 'a chi è atteso, se gli inviti sono già partiti. Senza allegato.',
                'oggetto' => 'EMAIL_PROMEMORIA_OGGETTO',
                'testo' => 'EMAIL_PROMEMORIA_TESTO',
                'predefinito' => [
                    'Oggi alle {ora_inizio}: {titolo}',
                    "Ciao {nome},\n\n"
                    . "oggi c'è l'incontro dal vivo «{titolo}», dalle {ora_inizio} alle {ora_fine}.\n\n"
                    . "Per partecipare:\n"
                    . "{link_meet}\n\n"
                    . "La pagina dell'incontro su {piattaforma}:\n"
                    . "{link_sessione}",
                ],
                'segnaposto' => $incontriSenzaCambio,
                'obbligatori' => [],
                'esempio' => $esempioIncontro,
            ],
            'incontro_cambio' => [
                'gruppo' => 'incontri',
                'titolo' => 'Cambio di orario',
                'quando' => 'Da sola, quando salvi un incontro non concluso con data o ora diverse da prima. '
                    . 'Ha allegato il file aggiornato per il calendario.',
                'oggetto' => 'LIVE_UPDATE_SUBJECT',
                'testo' => 'LIVE_UPDATE_BODY',
                'predefinito' => [LiveSessionMail::DEFAULTS['LIVE_UPDATE_SUBJECT'], LiveSessionMail::DEFAULTS['LIVE_UPDATE_BODY']],
                'segnaposto' => $incontri,
                'obbligatori' => [],
                'esempio' => $esempioIncontro,
            ],
            'incontro_annullato' => [
                'gruppo' => 'incontri',
                'titolo' => 'Incontro annullato',
                'quando' => 'Da sola, quando elimini un incontro non ancora concluso.',
                'oggetto' => 'LIVE_CANCEL_SUBJECT',
                'testo' => 'LIVE_CANCEL_BODY',
                'predefinito' => [LiveSessionMail::DEFAULTS['LIVE_CANCEL_SUBJECT'], LiveSessionMail::DEFAULTS['LIVE_CANCEL_BODY']],
                'segnaposto' => $incontri,
                'obbligatori' => [],
                'esempio' => $esempioIncontro,
            ],
        ];
    }

    /**
     * Le chiavi di `settings` che la pagina Messaggi email scrive: i dati comuni e
     * oggetto e testo di ogni email.
     *
     * @return list<string>
     */
    public static function chiaviSettings(): array
    {
        $chiavi = [self::ORGANIZZAZIONE, self::RISPONDI_A, self::CHIUSURA];

        foreach (self::elenco() as $voce) {
            $chiavi[] = $voce['oggetto'];
            $chiavi[] = $voce['testo'];
        }

        return $chiavi;
    }

    /**
     * Il nome dell'organizzazione impostato nel pannello; finche' non c'e',
     * il nome della piattaforma.
     */
    public static function organizzazione(): string
    {
        $nome = trim((string) Settings::get(self::ORGANIZZAZIONE, ''));

        return $nome !== '' ? $nome : self::PIATTAFORMA;
    }

    /** L'indirizzo per le risposte; finche' non c'e', quello del mittente. */
    public static function contatto(): string
    {
        $indirizzo = trim((string) Settings::get(self::RISPONDI_A, ''));

        return $indirizzo !== '' ? $indirizzo : (string) Settings::get('MAIL_FROM_ADDRESS', '');
    }

    /** Il testo in vigore: quello salvato dal pannello, o il predefinito. */
    public static function testoInVigore(string $chiaveSettings, string $predefinito): string
    {
        $salvato = Settings::get($chiaveSettings, '');

        return $salvato !== null && trim($salvato) !== '' ? $salvato : $predefinito;
    }

    /**
     * Sostituisce i segnaposto e ripulisce i vuoti lasciati dai campi
     * assenti: un `{messaggio}` vuoto non lascia tre righe bianche di fila.
     *
     * @param array<string, string> $variabili
     */
    public static function rendi(string $testo, array $variabili): string
    {
        $testo = strtr($testo, $variabili);
        $testo = preg_replace("/\n{3,}/", "\n\n", str_replace(["\r\n", "\r"], "\n", $testo)) ?? $testo;

        return trim($testo) . "\n";
    }

    /**
     * Il nome di battesimo di chi riceve: quello che arriva con il
     * destinatario, o quello del suo account letto dall'indirizzo, o in
     * mancanza il nome come e' stato passato.
     */
    public static function nomeDi(string $email, string $nomeCompleto, ?string $nome = null): string
    {
        if ($nome !== null && trim($nome) !== '') {
            return trim($nome);
        }

        if (!array_key_exists($email, self::$nomi)) {
            $stmt = Database::connection()->prepare('SELECT first_name FROM users WHERE email = :e LIMIT 1');
            $stmt->execute(['e' => $email]);
            self::$nomi[$email] = trim((string) ($stmt->fetchColumn() ?: ''));
        }

        return self::$nomi[$email] !== '' ? self::$nomi[$email] : trim($nomeCompleto);
    }

    /**
     * Compone un'email dell'elenco per un destinatario.
     *
     * @param array<string, string> $variabili i segnaposto propri di quell'email
     * @param array<int, array{filename: string, mimeType: string, content: string}> $allegati
     */
    public static function messaggio(
        string $chiave,
        string $email,
        string $nomeCompleto,
        array $variabili,
        array $allegati = [],
        ?string $nome = null
    ): Message {
        $voce = self::voce($chiave);

        return self::componi(
            $voce,
            self::testoInVigore($voce['oggetto'], $voce['predefinito'][0]),
            self::testoInVigore($voce['testo'], $voce['predefinito'][1]),
            $email,
            $nomeCompleto,
            $variabili,
            $allegati,
            $nome
        );
    }

    /**
     * La prova dal pannello: oggetto e testo come sono scritti nei campi,
     * anche non salvati (vuoti valgono il predefinito), con i valori di
     * esempio al posto dei segnaposto. L'oggetto comincia con «[Prova]».
     */
    public static function prova(string $chiave, string $email, string $nomeCompleto, string $oggetto, string $testo): Message
    {
        $voce = self::voce($chiave);
        $messaggio = self::componi(
            $voce,
            trim($oggetto) !== '' ? $oggetto : $voce['predefinito'][0],
            trim($testo) !== '' ? $testo : $voce['predefinito'][1],
            $email,
            $nomeCompleto,
            $voce['esempio'],
            [],
            null
        );
        $messaggio->subject = '[Prova] ' . $messaggio->subject;

        return $messaggio;
    }

    /**
     * @return array{gruppo: string, titolo: string, quando: string, oggetto: string, testo: string,
     *     predefinito: array{0: string, 1: string}, segnaposto: array<string, string>, obbligatori: list<string>,
     *     esempio: array<string, string>}
     */
    public static function voce(string $chiave): array
    {
        $voce = self::elenco()[$chiave] ?? null;

        if ($voce === null) {
            throw new \InvalidArgumentException('Email sconosciuta: ' . $chiave);
        }

        return $voce;
    }

    /**
     * @param array{oggetto: string, testo: string} $voce
     * @param array<string, string> $variabili
     * @param array<int, array{filename: string, mimeType: string, content: string}> $allegati
     */
    private static function componi(
        array $voce,
        string $oggetto,
        string $testo,
        string $email,
        string $nomeCompleto,
        array $variabili,
        array $allegati,
        ?string $nome
    ): Message {
        $tutte = $variabili + self::variabiliComuni(self::nomeDi($email, $nomeCompleto, $nome));

        // L'oggetto sta su una riga sola: un a capo lasciato in un segnaposto
        // spezzerebbe l'intestazione in due.
        $oggetto = trim(str_replace(["\r", "\n"], ' ', self::rendi($oggetto, $tutte)));

        $corpo = self::rendi($testo, $tutte)
            . "\n"
            . self::rendi(self::testoInVigore(self::CHIUSURA, self::CHIUSURA_PREDEFINITA), $tutte);

        $messaggio = new Message($email, $nomeCompleto, $oggetto, $corpo, $allegati);
        $rispondiA = trim((string) Settings::get(self::RISPONDI_A, ''));
        $messaggio->replyTo = $rispondiA !== '' ? $rispondiA : null;

        return $messaggio;
    }

    /**
     * Il nome con cui partono le email: quello impostato, o in mancanza il
     * nome dell'organizzazione.
     */
    public static function nomeMittente(): string
    {
        $nome = trim((string) Settings::get('MAIL_FROM_NAME', ''));

        return $nome !== '' ? $nome : self::organizzazione();
    }

    /**
     * I valori dei segnaposto comuni.
     *
     * @return array<string, string>
     */
    public static function variabiliComuni(string $nome): array
    {
        return [
            '{nome}' => $nome,
            '{organizzazione}' => self::organizzazione(),
            '{piattaforma}' => self::PIATTAFORMA,
            '{contatto}' => self::contatto(),
        ];
    }

    /**
     * Cosa non va in un testo prima di salvarlo: i segnaposto obbligatori
     * che mancano (bloccano il salvataggio) e quelli che non esistono (solo
     * un avviso). Un testo vuoto vale il predefinito, che va sempre bene.
     *
     * @param list<string> $ammessi
     * @param list<string> $obbligatori
     * @return array{mancanti: list<string>, sconosciuti: list<string>}
     */
    public static function controlla(string $testo, array $ammessi, array $obbligatori): array
    {
        if (trim($testo) === '') {
            return ['mancanti' => [], 'sconosciuti' => []];
        }

        $mancanti = array_values(array_filter($obbligatori, static fn (string $s): bool => !str_contains($testo, $s)));
        preg_match_all('/\{[a-z_]+\}/', $testo, $trovati);
        $sconosciuti = array_values(array_unique(array_filter(
            $trovati[0],
            static fn (string $s): bool => !in_array($s, $ammessi, true)
        )));

        return ['mancanti' => $mancanti, 'sconosciuti' => $sconosciuti];
    }

    /**
     * I segnaposto ammessi in un'email: i comuni e i suoi.
     *
     * @return list<string>
     */
    public static function ammessi(string $chiave): array
    {
        return array_merge(array_keys(self::segnapostoComuni()), array_keys(self::voce($chiave)['segnaposto']));
    }
}
