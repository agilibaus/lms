<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Il collegamento «indietro» che torna alla pagina di partenza (17/11,
 * Alessandro).
 *
 * Una pagina che si apre da piu' parti ha un «indietro» fisso, che porta a
 * una sola di quelle: arrivando da un'altra, si tornava nel posto sbagliato
 * (dall'Agenda a «Sessioni live», dal report di uno studente alla pagina del
 * corso, da Report › Corsi a «Report»). Adesso il collegamento verso la
 * pagina dice da dove parte, e la pagina ci torna, con i parametri che la
 * pagina di partenza aveva (filtri, ricerca, ordinamento, scheda).
 *
 * L'INDIRIZZO. `?da=chiave` dice la pagina di partenza, `da_id=` il suo id
 * se ne ha uno, `torna=` i suoi parametri. Il percorso lo scrive sempre il
 * server, da `ORIGINI`: dall'indirizzo arrivano solo una chiave conosciuta,
 * un numero e coppie nome=valore, quindi non si puo' portare altrove. Ogni
 * pagina dice quali partenze accetta; le altre si ignorano e resta il suo
 * «indietro» di sempre.
 *
 * Il report dello studente aperto da un corso o da un gruppo ha la sua
 * classe, `ContestoStudente`, perche' li' il corso o il gruppo cambiano
 * anche il contenuto della pagina, non solo il ritorno.
 *
 * Qui solo l'indirizzo: che cosa dice il collegamento (il titolo della
 * pagina di partenza) lo decide chi lo stampa, che ha i dati.
 */
final class Ritorno
{
    /** Le pagine da cui si parte, con il loro percorso. */
    public const ORIGINI = [
        'elenco-corsi' => '/reports/elenco/courses',
        'elenco-gruppi' => '/reports/elenco/groups',
        'elenco-studenti' => '/reports/elenco/students',
        'elenco-incontri' => '/reports/elenco/live',
        'elenco-fruizione' => '/reports/elenco/fruizione',
        'report-studente' => '/reports/students/{id}',
        'report-incontro' => '/reports/live/{id}',
        'report-fruizione' => '/reports/fruizione/{id}',
        'agenda' => '/agenda',
        'gruppo' => '/gruppi/{id}',
        'gruppi' => '/admin/groups',
        'scheda-gruppo' => '/admin/groups/{id}/edit',
        'scheda-utente' => '/admin/users/{id}/edit',
        'categoria' => '/bacheca/categorie/{id}',
        'gestione-corsi' => '/admin/courses',
        'scheda-corso' => '/admin/courses/{id}/edit',
        'lezione' => '/lessons/{id}',
        'questionario' => '/quizzes/{id}',
    ];

    /** Oltre questa lunghezza `torna` non e' un indirizzo nostro: si lascia. */
    private const TORNA_MAX = 2000;

    /**
     * Il collegamento a `$url` che sa tornare alla pagina di partenza.
     *
     * @param array<string, mixed> $parametri quelli della pagina di partenza
     *        (di solito `$_GET`), per tornarci com'era
     */
    public static function link(string $url, string $da, int $id = 0, array $parametri = []): string
    {
        if (!isset(self::ORIGINI[$da])) {
            throw new \InvalidArgumentException('Pagina di partenza sconosciuta: ' . $da);
        }

        $q = ['da' => $da];

        if (str_contains(self::ORIGINI[$da], '{id}')) {
            $q['da_id'] = $id;
        }

        $torna = self::pulisci($parametri);

        if ($torna !== []) {
            $q['torna'] = http_build_query($torna);
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($q);
    }

    /**
     * La pagina di partenza chiesta dall'indirizzo, se e' fra quelle che la
     * pagina accetta.
     *
     * @param array<string, mixed> $get
     * @param list<string> $ammesse
     * @return array{da: string, id: int, torna: array<string, string>}|null
     */
    public static function daRichiesta(array $get, array $ammesse): ?array
    {
        $da = $get['da'] ?? null;

        if (!is_string($da) || !isset(self::ORIGINI[$da]) || !in_array($da, $ammesse, true)) {
            return null;
        }

        $id = 0;

        if (str_contains(self::ORIGINI[$da], '{id}')) {
            $grezzo = $get['da_id'] ?? null;

            if (!is_string($grezzo) || !ctype_digit($grezzo) || (int) $grezzo <= 0) {
                return null;
            }

            $id = (int) $grezzo;
        }

        $torna = [];

        if (is_string($get['torna'] ?? null) && strlen($get['torna']) <= self::TORNA_MAX) {
            parse_str($get['torna'], $letti);
            $torna = self::pulisci($letti);
        }

        return ['da' => $da, 'id' => $id, 'torna' => $torna];
    }

    /**
     * L'«indietro» di una pagina: dove porta e che cosa dice.
     *
     * `$partenze` sono le partenze accettate, ciascuna con una funzione che,
     * dato l'id della partenza, dice il titolo di quella pagina, o null se
     * l'id non va bene (un altro corso, un utente che non c'e'). Senza una
     * partenza buona, l'«indietro» di sempre.
     *
     * @param array<string, mixed> $get
     * @param array<string, callable(int): ?string> $partenze
     * @return array{0: string, 1: string} indirizzo e testo
     */
    public static function indietro(array $get, array $partenze, string $indirizzo, string $testo): array
    {
        $ritorno = self::daRichiesta($get, array_keys($partenze));

        if ($ritorno !== null) {
            $titolo = ($partenze[$ritorno['da']])($ritorno['id']);

            if ($titolo !== null) {
                return [self::indirizzo($ritorno), $titolo];
            }
        }

        return [$indirizzo, $testo];
    }

    /** @param array{da: string, id: int, torna: array<string, string>} $ritorno */
    public static function indirizzo(array $ritorno): string
    {
        $url = str_replace('{id}', (string) $ritorno['id'], self::ORIGINI[$ritorno['da']]);

        return $ritorno['torna'] === [] ? $url : $url . '?' . http_build_query($ritorno['torna']);
    }

    /**
     * Il ritorno sopravvive a un salvataggio (17/11, Alessandro).
     *
     * Una pagina con un ritorno (la scheda di un corso aperta da un gruppo,
     * un incontro aperto dal suo report) ha dei moduli: salvando, il
     * controller rimanda alla stessa pagina senza parametri, e l'«indietro»
     * tornava quello di sempre. Qui, in un punto solo invece che in ognuno
     * dei rimandi: se una richiesta POST rimanda alla stessa pagina da cui
     * e' partito il modulo (`Referer`, stesso sito e stesso percorso), e
     * quella pagina aveva un ritorno, il rimando se lo porta dietro.
     *
     * Senza `Referer` (qualche browser lo toglie) il ritorno si perde come
     * prima: niente di peggio.
     *
     * @param string $location il rimando scelto dal controller
     * @param string $referer  la pagina da cui e' partito il modulo
     * @param string $host     il sito di questa richiesta
     * @return string il rimando, con il ritorno se serve
     */
    public static function dopoIlSalvataggio(string $location, string $referer, string $host): string
    {
        if (!str_starts_with($location, '/') || str_starts_with($location, '//') || $referer === '') {
            return $location;
        }

        $da = parse_url($referer);

        if (!is_array($da) || ($da['host'] ?? '') === '' || strcasecmp((string) $da['host'], explode(':', $host)[0]) !== 0) {
            return $location;
        }

        [$senzaAncora, $ancora] = array_pad(explode('#', $location, 2), 2, null);
        $percorso = (string) parse_url($senzaAncora, PHP_URL_PATH);

        if ($percorso !== ($da['path'] ?? '')) {
            return $location;
        }

        parse_str((string) ($da['query'] ?? ''), $parametri);
        parse_str((string) parse_url($senzaAncora, PHP_URL_QUERY), $gia);
        $ritorno = array_intersect_key(self::pulisci($parametri), ['da' => 1, 'da_id' => 1, 'torna' => 1]);

        if (!isset($ritorno['da']) || isset($gia['da'])) {
            return $location;
        }

        return $senzaAncora . (str_contains($senzaAncora, '?') ? '&' : '?') . http_build_query($ritorno)
            . ($ancora === null ? '' : '#' . $ancora);
    }

    /**
     * Da chiamare all'avvio di ogni richiesta POST: riscrive il rimando,
     * se c'e', appena prima che partano le intestazioni.
     */
    public static function tieniDopoIlSalvataggio(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }

        header_register_callback(static function (): void {
            foreach (headers_list() as $intestazione) {
                if (stripos($intestazione, 'Location:') === 0) {
                    $location = trim(substr($intestazione, 9));
                    $nuova = self::dopoIlSalvataggio(
                        $location,
                        (string) ($_SERVER['HTTP_REFERER'] ?? ''),
                        (string) ($_SERVER['HTTP_HOST'] ?? '')
                    );

                    if ($nuova !== $location) {
                        header('Location: ' . $nuova);
                    }
                }
            }
        });
    }

    /**
     * Solo coppie nome=valore semplici. `torna` puo' essere lungo: e' il
     * ritorno della pagina di partenza, quando anche lei ne ha uno (il
     * tentativo aperto dal report di uno studente aperto da un corso).
     *
     * @param array<mixed> $parametri
     * @return array<string, string>
     */
    public static function pulisci(array $parametri): array
    {
        $puliti = [];

        foreach ($parametri as $nome => $valore) {
            $massimo = $nome === 'torna' ? self::TORNA_MAX : 100;

            if (is_string($nome) && preg_match('/^[a-z_]{1,30}$/', $nome) === 1
                && (is_string($valore) || is_int($valore)) && strlen((string) $valore) <= $massimo) {
                $puliti[$nome] = (string) $valore;
            }
        }

        return $puliti;
    }
}
