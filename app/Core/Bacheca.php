<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Le regole della Bacheca che non chiedono niente al database (17/10,
 * Alessandro). Chi vede, chi pubblica e chi modera stanno in
 * `App\Auth\BachecaScope`; i dati in `App\Models\BoardModel`.
 *
 * LE DECISIONI
 *   - una bacheca per corso, divisa in categorie che crea l'admin, ognuna
 *     con uno dei quattro colori del tema e una descrizione breve;
 *   - pubblicano l'admin e i tutor del corso, anche con un file (le stesse
 *     regole dei materiali delle lezioni); gli studenti propongono, senza
 *     file, e la proposta aspetta il tutor del loro gruppo o l'admin;
 *   - video e podcast sono solo link, mai incorporati;
 *   - dentro una categoria, prima quelle in evidenza, poi dalla piu' recente.
 */
final class Bacheca
{
    public const PENDING = 'pending';
    public const PUBLISHED = 'published';
    public const DISCARDED = 'discarded';

    public const MAX_TITOLO = 150;
    public const MAX_DESCRIZIONE = 1000;
    public const MAX_LINK = 500;
    public const MAX_USO = 500;
    public const MAX_NOME_CATEGORIA = 80;
    public const MAX_DESCRIZIONE_CATEGORIA = 200;

    /** I tipi di risorsa, con il nome che si legge. */
    public const TIPI = [
        'scheda' => 'Scheda pratica',
        'checklist' => 'Promemoria',
        'video' => 'Video',
        'podcast' => 'Podcast',
        'articolo' => 'Articolo',
        'altro' => 'Altro',
    ];

    /** I tipi che sono solo un link: niente file, e il link e' obbligatorio. */
    public const SOLO_LINK = ['video', 'podcast'];

    /**
     * I colori delle categorie (17/10, Alessandro, variante B): 64 colori
     * in una tavolozza 8x8, una riga per tinta e otto toni dal piu' chiaro
     * al piu' scuro. Sono toni smorzati come quelli del tema, e contengono
     * i quattro colori delle tavolozze del tema, cosi' le categorie create
     * prima restano come erano.
     *
     * TUTTI MEDI O SCURI, E NON PER GUSTO. Il titolo del riquadro e' scritto
     * nel colore sulla sua tinta chiara, e deve leggersi: almeno 4,5:1. Un
     * giallo, un rosa o un azzurro chiaro non ci arrivano. Li ha generati
     * uno script in OKLCH, con il tono piu' chiaro di ogni riga al limite
     * del contrasto; `tests/bacheca_test.php` rifa' il conto su tutti e 64.
     */
    public const TAVOLOZZA = [
        'rosso' => ['Rosso', ['#975455', '#8E4C4C', '#854345', '#7C3B3D', '#6E3738', '#603333', '#532E2F', '#462A2A']],
        'terracotta' => ['Terracotta', ['#9B4F2F', '#8A4F33', '#82472B', '#793F23', '#6B3A23', '#5E3523', '#513122', '#452C21']],
        'ocra' => ['Ocra', ['#826122', '#7A5918', '#72510C', '#6A4900', '#5F430A', '#533D12', '#493716', '#3E3019']],
        'oliva' => ['Oliva', ['#656C2A', '#5D6421', '#555C18', '#4E550E', '#474D14', '#404518', '#393D1A', '#32351C']],
        'verde' => ['Verde', ['#4F7256', '#336B41', '#2B643A', '#235C33', '#245330', '#244A2D', '#24412A', '#233927']],
        'petrolio' => ['Petrolio', ['#007378', '#066A6F', '#036266', '#015A5E', '#005256', '#004A4E', '#0B4245', '#14393B']],
        'ardesia' => ['Ardesia', ['#366C98', '#3F6079', '#255C86', '#1C547E', '#1E4C70', '#204462', '#203D54', '#203547']],
        'prugna' => ['Prugna', ['#81598B', '#795082', '#6B4A77', '#674070', '#5C3B64', '#513658', '#47314C', '#3C2C40']],
    ];

    /** Le chiavi di prima (le tavolozze del tema), per le righe non migrate. */
    private const VECCHIE_CHIAVI = ['verde', 'ardesia', 'terracotta', 'prugna'];

    /** Quanto bianco nella tinta chiara di un colore che non e' del tema. */
    private const BIANCO_TINTA = 0.89;

    /**
     * Gli stessi tipi di file dei materiali delle lezioni (`LessonController`),
     * lo controlla `tests/bacheca_test.php`. La dimensione no: 3 MB (17/10,
     * Alessandro), perche' una scheda pratica o un promemoria in PDF pesano
     * poche centinaia di kB, e la bacheca non e' un archivio di file.
     */
    public const FILE_EXTENSIONS = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'mp3', 'wav', 'm4a', 'zip', 'txt'];
    public const FILE_MAX_BYTES = 3 * 1024 * 1024;

    /**
     * Il limite vero di un file: il piu' basso fra il nostro e quello del
     * php.ini (`upload_max_filesize`, `post_max_size`). Annunciare 3 MB su un
     * server che ne accetta 2 farebbe perdere tempo a chi carica.
     */
    public static function limiteFile(): int
    {
        return min(self::FILE_MAX_BYTES, Upload::iniLimitBytes());
    }

    public static function conta(int $quante): string
    {
        return match (true) {
            $quante <= 0 => 'Nessuna risorsa',
            $quante === 1 => '1 risorsa',
            default => $quante . ' risorse',
        };
    }

    public static function daValutare(int $quante): string
    {
        return $quante === 1 ? '1 proposta da valutare' : $quante . ' proposte da valutare';
    }

    /**
     * Il colore di una categoria e la sua tinta chiara. Un colore del tema ha
     * la tinta del tema (la stessa di prima); gli altri l'89% di bianco. Un
     * valore fuori dalla tavolozza torna verde.
     *
     * @return array{primary: string, soft: string}
     */
    public static function colore(string $valore): array
    {
        $hex = in_array($valore, self::VECCHIE_CHIAVI, true)
            ? Theme::TAVOLOZZE[$valore]['primary']
            : (self::nellaTavolozza($valore) ?? Theme::TAVOLOZZE['verde']['primary']);

        foreach (Theme::TAVOLOZZE as $t) {
            if (strtoupper($t['primary']) === $hex) {
                return ['primary' => $hex, 'soft' => $t['soft']];
            }
        }

        return ['primary' => $hex, 'soft' => Theme::mescolaVerso($hex, '#FFFFFF', self::BIANCO_TINTA)];
    }

    /** Il colore scritto come nella tavolozza, se ci sta; altrimenti null. */
    public static function nellaTavolozza(string $valore): ?string
    {
        $hex = strtoupper(trim($valore));

        foreach (self::TAVOLOZZA as [, $toni]) {
            if (in_array($hex, $toni, true)) {
                return $hex;
            }
        }

        return null;
    }

    /** «Verde, tono 1»: il nome che si legge e che sentono i lettori di schermo. */
    public static function nomeColore(string $valore): string
    {
        $hex = self::colore($valore)['primary'];

        foreach (self::TAVOLOZZA as [$nome, $toni]) {
            $i = array_search($hex, $toni, true);

            if ($i !== false) {
                return $nome . ', tono ' . ($i + 1);
            }
        }

        return $hex;
    }

    public static function etichettaLink(string $tipo): string
    {
        return match ($tipo) {
            'video' => 'Apri il video',
            'podcast' => 'Apri il podcast',
            'articolo' => 'Apri l’articolo',
            default => 'Apri il link',
        };
    }

    /**
     * Il link come si salva: '' se non c'e', null se non va bene. Solo http
     * e https (un `javascript:` aperto dalla bacheca girerebbe con la sessione
     * di chi lo clicca); senza schema si aggiunge https.
     */
    public static function link(string $valore): ?string
    {
        $valore = trim($valore);

        if ($valore === '') {
            return '';
        }

        if (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $valore)) {
            $valore = 'https://' . ltrim($valore, '/');
        }

        if (mb_strlen($valore) > self::MAX_LINK || preg_match('/\s/u', $valore)) {
            return null;
        }

        $schema = strtolower((string) parse_url($valore, PHP_URL_SCHEME));
        $host = (string) parse_url($valore, PHP_URL_HOST);

        if (!in_array($schema, ['http', 'https'], true) || $host === '' || filter_var($valore, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $valore;
    }

    /**
     * Prima quelle in evidenza, poi dalla piu' recente.
     *
     * @param list<array<string, mixed>> $risorse
     * @return list<array<string, mixed>>
     */
    public static function ordina(array $risorse): array
    {
        usort($risorse, static fn (array $a, array $b): int
            => [(int) $b['featured'], (string) $b['published_at'], (int) $b['id']]
            <=> [(int) $a['featured'], (string) $a['published_at'], (int) $a['id']]);

        return $risorse;
    }

    /**
     * I campi di una risorsa dal modulo, controllati: titolo, tipo,
     * categoria (fra quelle del corso), link, descrizione.
     *
     * @param array<string, mixed> $campi
     * @param list<int> $categorie
     * @return array{dati: array<string, mixed>, errore: ?string}
     */
    public static function valida(array $campi, array $categorie): array
    {
        $titolo = trim((string) ($campi['title'] ?? ''));
        $tipo = (string) ($campi['kind'] ?? '');
        $categoria = (int) ($campi['category_id'] ?? 0);
        $link = self::link((string) ($campi['url'] ?? ''));
        $descrizione = trim(str_replace("\r\n", "\n", (string) ($campi['description'] ?? '')));

        $dati = [
            'title' => $titolo,
            'kind' => $tipo,
            'category_id' => $categoria,
            'url' => $link === '' ? null : $link,
            'description' => $descrizione,
        ];

        $errore = match (true) {
            $titolo === '' => 'Scrivi il titolo della risorsa.',
            mb_strlen($titolo) > self::MAX_TITOLO => 'Il titolo supera i ' . self::MAX_TITOLO . ' caratteri.',
            !isset(self::TIPI[$tipo]) => 'Scegli il tipo di risorsa.',
            !in_array($categoria, $categorie, true) => 'Scegli una categoria di questa bacheca.',
            $link === null => 'Il link non è valido: deve essere un indirizzo web, che comincia con https://.',
            $descrizione === '' => 'Scrivi la descrizione: che cos’è, a cosa serve, quando può essere utile, come usarla.',
            mb_strlen($descrizione) > self::MAX_DESCRIZIONE => 'La descrizione supera i ' . self::MAX_DESCRIZIONE . ' caratteri.',
            in_array($tipo, self::SOLO_LINK, true) && $link === '' => 'Per un video o un podcast serve il link.',
            default => null,
        };

        return ['dati' => $dati, 'errore' => $errore];
    }

    /**
     * Chi l'ha condivisa, come si legge sotto la risorsa. Lo staff come
     * ruolo, lo studente con il nome come lo vede chi guarda
     * (`PersonName::shown`, gia' calcolato da chi chiama).
     */
    public static function attribuzione(?string $ruolo, string $nome): string
    {
        return match ($ruolo) {
            'tutor' => 'Dal tutor',
            'admin', 'assistente' => 'Dallo staff',
            null => 'Condivisa in bacheca',
            default => 'Proposta da ' . $nome,
        };
    }

    /**
     * Dove tornare dopo un'azione: solo una pagina della bacheca, scritta
     * come percorso. Il valore arriva da un campo del modulo, quindi da
     * chiunque: un indirizzo di un altro sito diventerebbe un rimando
     * aperto.
     */
    public static function torna(string $dove, string $altrimenti): string
    {
        if (preg_match('#^/bacheca(?:[/?\#][^\s\\\\]*)?$#', $dove) === 1 && !str_starts_with($dove, '//')) {
            return $dove;
        }

        return $altrimenti;
    }
}
