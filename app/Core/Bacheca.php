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
        'scheda' => 'Scheda',
        'checklist' => 'Checklist',
        'video' => 'Video',
        'podcast' => 'Podcast',
        'articolo' => 'Articolo',
        'altro' => 'Altro',
    ];

    /** I tipi che sono solo un link: niente file, e il link e' obbligatorio. */
    public const SOLO_LINK = ['video', 'podcast'];

    /**
     * I colori delle categorie: le quattro tavolozze del tema, con il loro
     * colore e la loro tinta chiara. Gia' verificate per il contrasto
     * (`tests/tema_test.php`): il titolo colorato sulla tinta chiara e il
     * testo normale sulla tinta chiara si leggono tutti.
     */
    public const COLORI = [
        'verde' => 'Verde',
        'ardesia' => 'Ardesia',
        'terracotta' => 'Terracotta',
        'prugna' => 'Prugna',
    ];

    /**
     * Gli stessi file dei materiali delle lezioni (`LessonController`), e la
     * stessa dimensione: lo controlla `tests/bacheca_test.php`.
     */
    public const FILE_EXTENSIONS = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'mp3', 'wav', 'm4a', 'zip', 'txt'];
    public const FILE_MAX_BYTES = 50 * 1024 * 1024;

    public static function conta(int $quante): string
    {
        return match (true) {
            $quante <= 0 => 'Ancora nessuna risorsa',
            $quante === 1 => '1 risorsa',
            default => $quante . ' risorse',
        };
    }

    public static function daValutare(int $quante): string
    {
        return $quante === 1 ? '1 proposta da valutare' : $quante . ' proposte da valutare';
    }

    /** @return array{primary: string, soft: string} */
    public static function colore(string $chiave): array
    {
        $t = Theme::TAVOLOZZE[isset(self::COLORI[$chiave]) ? $chiave : 'verde'];

        return ['primary' => $t['primary'], 'soft' => $t['soft']];
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
