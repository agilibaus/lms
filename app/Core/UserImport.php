<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Legge un elenco di persone da un file CSV e lo prepara all'importazione.
 *
 * QUI NON SI SCRIVE NIENTE. Questa classe legge, interpreta e giudica: dice
 * quali righe sono buone, quali no e perche'. A scrivere nel database ci
 * pensa il controller, dopo che un essere umano ha guardato l'anteprima.
 * La separazione non e' eleganza: e' quello che permette di provare tutta
 * la parte difficile — codifiche, separatori, intestazioni, email storte —
 * senza un database e senza un browser.
 *
 * LE TRE COSE CHE VANNO STORTE, in ordine di frequenza:
 *
 *  1. **La codifica.** Excel su Windows esporta volentieri in Windows-1252,
 *     o in UTF-8 con il BOM davanti. Nel primo caso «Nicolo'» arriva
 *     mangiato, nel secondo la prima intestazione si chiama «\xEF\xBB\xBFemail»
 *     e non viene riconosciuta. Si riconoscono e si sistemano tutti e due.
 *  2. **Il separatore.** Excel in italiano scrive il «CSV» con il punto e
 *     virgola, perche' la virgola la usa per i decimali. Un lettore che
 *     accetta solo la virgola vede una colonna sola e dice che manca
 *     l'email, che e' il messaggio piu' fuorviante possibile.
 *  3. **Le intestazioni.** La gente scrive «E-mail», «Nome e Cognome»,
 *     «Classe». Si riconoscono per sinonimo, ignorando maiuscole e spazi.
 */
class UserImport
{
    /** Quante righe si accettano in un file solo. */
    public const MAX_RIGHE = 1000;

    /**
     * I nomi che riconosciamo per ogni colonna, gia' normalizzati.
     *
     * L'ordine conta per il nome: «nome completo» va provato prima di
     * «nome», altrimenti un file con una colonna sola chiamata «nome
     * completo» finirebbe interpretato come il solo nome di battesimo.
     */
    private const INTESTAZIONI = [
        'email' => ['email', 'e-mail', 'mail', 'indirizzo email', 'posta elettronica'],
        'nome_completo' => ['nome completo', 'nome e cognome', 'nominativo'],
        'nome' => ['nome'],
        'cognome' => ['cognome'],
        'gruppo' => ['gruppo', 'classe'],
    ];

    /**
     * Interpreta il contenuto di un file.
     *
     * @param list<string> $gruppiEsistenti nomi dei gruppi, per dire subito
     *        quando il file ne nomina uno che non c'e'
     * @return array{
     *     colonne: array<string, int>,
     *     righe: list<array{numero: int, email: string, nome: string, first_name: string, last_name: string,
     *                       gruppo: string, errore: ?string}>,
     *     errore: ?string
     * }
     */
    public static function leggi(string $contenuto, array $gruppiEsistenti = []): array
    {
        $contenuto = self::aUtf8($contenuto);
        $linee = preg_split("/\r\n|\n|\r/", $contenuto) ?: [];
        $linee = array_values(array_filter($linee, static fn (string $l): bool => trim($l) !== ''));

        if ($linee === []) {
            return self::vuoto('Il file è vuoto.');
        }

        $separatore = self::separatore($linee[0]);
        $intestazione = str_getcsv($linee[0], $separatore, '"', '\\');
        $colonne = self::mappaColonne($intestazione);

        if (!isset($colonne['email'])) {
            return self::vuoto(
                'Manca la colonna «email». La prima riga del file deve contenere i nomi '
                . 'delle colonne: email, nome (oppure nome e cognome separati) e gruppo.'
            );
        }

        if (!isset($colonne['nome_completo']) && !isset($colonne['nome'])) {
            return self::vuoto(
                'Manca la colonna con il nome. Serve «nome completo», oppure «nome» e '
                . '«cognome» in due colonne separate.'
            );
        }

        $dati = array_slice($linee, 1);

        if (count($dati) > self::MAX_RIGHE) {
            return self::vuoto(
                'Il file ha ' . count($dati) . ' righe: il massimo è ' . self::MAX_RIGHE
                . '. Spezzalo in più file.'
            );
        }

        // I gruppi si confrontano senza badare a maiuscole e spazi, perche'
        // «Classe A» e «classe a» sono lo stesso gruppo per chiunque tranne
        // che per un confronto fra stringhe.
        $gruppi = [];

        foreach ($gruppiEsistenti as $g) {
            $gruppi[self::normalizza($g)] = $g;
        }

        $righe = [];
        $visti = [];

        foreach ($dati as $i => $linea) {
            $campi = str_getcsv($linea, $separatore, '"', '\\');
            $email = mb_strtolower(trim(self::campo($campi, $colonne, 'email')));
            [$primo, $cognome] = self::nome($campi, $colonne);
            $nome = trim($primo . ' ' . $cognome);
            $gruppo = trim(self::campo($campi, $colonne, 'gruppo'));
            $errore = null;

            if ($email === '') {
                $errore = 'manca l\'email';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errore = 'l\'email non è valida';
            } elseif (isset($visti[$email])) {
                // Due volte nello stesso file: il duplicato va detto qui e
                // non scoperto dal database, che direbbe solo «esiste gia'»
                // senza spiegare che l'hai scritto tu due volte.
                $errore = 'ripetuta nel file (riga ' . $visti[$email] . ')';
            } elseif ($primo === '') {
                $errore = 'manca il nome';
            } elseif ($cognome === '') {
                // Il cognome serve (07/10): da lui si ricavano le iniziali
                // per chi sceglie di comparire cosi'.
                $errore = 'manca il cognome';
            } elseif ($gruppo !== '' && !isset($gruppi[self::normalizza($gruppo)])) {
                // Il gruppo non si crea al volo: un refuso creerebbe un
                // gruppo nuovo invece di segnalare l'errore, e nessuno se ne
                // accorgerebbe fino a quando qualcuno non si ritrova solo
                // dentro «Clssse A».
                $errore = 'il gruppo «' . $gruppo . '» non esiste';
            }

            if ($errore === null) {
                $visti[$email] = $i + 2;
            }

            $righe[] = [
                // +2: la prima riga di dati e' la seconda del file, e chi
                // guarda l'anteprima ha il file aperto davanti.
                'numero' => $i + 2,
                'email' => $email,
                'nome' => $nome,
                'first_name' => $primo,
                'last_name' => $cognome,
                'gruppo' => $gruppo === '' ? '' : ($gruppi[self::normalizza($gruppo)] ?? $gruppo),
                'errore' => $errore,
            ];
        }

        return ['colonne' => $colonne, 'righe' => $righe, 'errore' => null];
    }

    /**
     * Il separatore: quello che compare di piu' nell'intestazione.
     *
     * Non si indovina dal nome del file ne' si chiede all'utente: si conta.
     * Un'intestazione «email;nome;gruppo» ha due punti e virgola e zero
     * virgole, e non c'e' altro da sapere.
     */
    public static function separatore(string $intestazione): string
    {
        $candidati = [';' => substr_count($intestazione, ';'), ',' => substr_count($intestazione, ','), "\t" => substr_count($intestazione, "\t")];
        arsort($candidati);
        $vincitore = (string) array_key_first($candidati);

        return $candidati[$vincitore] > 0 ? $vincitore : ',';
    }

    /**
     * Porta il contenuto in UTF-8, qualunque cosa sia arrivato.
     *
     * Il BOM va tolto **prima** di qualunque altra cosa: resta attaccato al
     * primo carattere del file, cioe' alla prima intestazione, e la rende
     * irriconoscibile senza che si veda niente di strano guardandola.
     */
    public static function aUtf8(string $contenuto): string
    {
        if (str_starts_with($contenuto, "\xEF\xBB\xBF")) {
            return substr($contenuto, 3);
        }

        if (mb_check_encoding($contenuto, 'UTF-8')) {
            return $contenuto;
        }

        // Non e' UTF-8 valido: quasi sempre e' Windows-1252, che e' quello
        // che Excel produce su Windows quando si salva «CSV (delimitato dal
        // separatore di elenco)».
        return (string) mb_convert_encoding($contenuto, 'UTF-8', 'Windows-1252');
    }

    /**
     * Le righe buone, pronte da scrivere.
     *
     * @param list<array<string, mixed>> $righe
     * @return list<array<string, mixed>>
     */
    public static function buone(array $righe): array
    {
        return array_values(array_filter($righe, static fn (array $r): bool => $r['errore'] === null));
    }

    /**
     * @param list<array<string, mixed>> $righe
     * @return list<array<string, mixed>>
     */
    public static function scartate(array $righe): array
    {
        return array_values(array_filter($righe, static fn (array $r): bool => $r['errore'] !== null));
    }

    /** @param list<string> $intestazione @return array<string, int> */
    private static function mappaColonne(array $intestazione): array
    {
        $colonne = [];

        foreach ($intestazione as $i => $titolo) {
            $t = self::normalizza((string) $titolo);

            foreach (self::INTESTAZIONI as $campo => $sinonimi) {
                if (!isset($colonne[$campo]) && in_array($t, $sinonimi, true)) {
                    $colonne[$campo] = $i;
                    break;
                }
            }
        }

        return $colonne;
    }

    /** @param list<string> $campi @param array<string, int> $colonne */
    private static function campo(array $campi, array $colonne, string $nome): string
    {
        if (!isset($colonne[$nome])) {
            return '';
        }

        return (string) ($campi[$colonne[$nome]] ?? '');
    }

    /**
     * Nome e cognome, da una colonna sola o da due (07/10: nome e cognome
     * sono due campi). Un nome tutto insieme — colonna «nome completo», o
     * una colonna «nome» senza la colonna «cognome» — si divide con
     * `PersonName::split()`: la prima parola e' il nome, il resto il
     * cognome. Sbaglia con i nomi doppi, ed e' per questo che il formato
     * consigliato ha le due colonne.
     *
     * @param list<string> $campi
     * @param array<string, int> $colonne
     * @return array{0: string, 1: string}
     */
    private static function nome(array $campi, array $colonne): array
    {
        $completo = trim(self::campo($campi, $colonne, 'nome_completo'));

        if ($completo === '' && !isset($colonne['cognome'])) {
            $completo = trim(self::campo($campi, $colonne, 'nome'));
        }

        if ($completo !== '') {
            return PersonName::split((string) preg_replace('/\s+/u', ' ', $completo));
        }

        return [
            trim((string) preg_replace('/\s+/u', ' ', self::campo($campi, $colonne, 'nome'))),
            trim((string) preg_replace('/\s+/u', ' ', self::campo($campi, $colonne, 'cognome'))),
        ];
    }

    private static function normalizza(string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s) ?? $s));
    }

    /** @return array{colonne: array<string, int>, righe: list<array<string, mixed>>, errore: string} */
    private static function vuoto(string $errore): array
    {
        return ['colonne' => [], 'righe' => [], 'errore' => $errore];
    }
}
