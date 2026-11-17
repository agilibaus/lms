<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Il report di uno studente aperto da un corso o da un gruppo (17/11,
 * Alessandro, strada A).
 *
 * Arrivando da «Report › Corsi › un corso › uno studente» la pagina mostrava
 * tutti i corsi dello studente: il percorso si perdeva. Adesso il
 * collegamento dice da dove si viene e su che cosa, e la pagina mostra solo
 * quello: l'avanzamento in quel corso (o nei corsi di quel gruppo) e gli
 * incontri che lo riguardano. Il collegamento in alto riporta alla pagina di
 * partenza, con i suoi filtri e il suo ordinamento; i file scaricati seguono
 * lo stesso contesto.
 *
 * L'INDIRIZZO. `?corso=ID` o `?gruppo=ID`, con `da=` che dice quale pagina
 * del corso o del gruppo (sono piu' d'una) e `torna=` che porta i parametri
 * di quella pagina. Il percorso di ritorno lo scrive sempre il server, a
 * partire da `da` e dall'id: da `torna` si prendono solo coppie nome=valore,
 * quindi l'indirizzo non puo' portare altrove.
 *
 * Qui solo l'indirizzo, senza database: se il corso o il gruppo sono davvero
 * dello studente, e visibili a chi guarda, lo decide `ReportController`. Un
 * contesto che non regge si ignora e si vede la scheda completa.
 */
final class ContestoStudente
{
    /** Da dove si puo' arrivare con un corso, e dove si torna. */
    private const DA_CORSO = [
        'corso' => '/reports/courses/{id}',
        'griglia' => '/reports/courses/{id}/griglia',
        'fermi' => '/reports/fermi',
        'gestione' => '/admin/courses/{id}/edit',
    ];

    /** Da dove si puo' arrivare con un gruppo, e dove si torna. */
    private const DA_GRUPPO = [
        'gruppo' => '/reports/groups/{id}',
        'scheda-gruppo' => '/admin/groups/{id}/edit',
    ];

    /** Oltre questa lunghezza `torna` non e' un indirizzo nostro: si lascia. */
    private const TORNA_MAX = 500;

    /**
     * Il collegamento allo studente da una pagina di corso o di gruppo.
     *
     * @param array<string, mixed> $parametri quelli della pagina di partenza
     *        (di solito `$_GET`), per tornarci com'era
     */
    public static function link(int $studentId, string $da, int $id, array $parametri = []): string
    {
        $tipo = isset(self::DA_GRUPPO[$da]) ? 'gruppo' : 'corso';
        $q = [$tipo => $id, 'da' => $da];
        $torna = self::pulisci($parametri);

        if ($torna !== []) {
            $q['torna'] = http_build_query($torna);
        }

        return '/reports/students/' . $studentId . '?' . http_build_query($q);
    }

    /**
     * Il contesto chiesto dall'indirizzo, o null.
     *
     * @param array<string, mixed> $get
     * @return array{tipo: string, id: int, da: string, torna: array<string, string>}|null
     */
    public static function daRichiesta(array $get): ?array
    {
        foreach (['corso' => self::DA_CORSO, 'gruppo' => self::DA_GRUPPO] as $tipo => $pagine) {
            $grezzo = $get[$tipo] ?? null;

            if (!is_string($grezzo) || !ctype_digit($grezzo) || (int) $grezzo <= 0) {
                continue;
            }

            $da = $get['da'] ?? null;

            if (!is_string($da) || !isset($pagine[$da])) {
                // Senza `da`, o con un `da` dell'altro tipo, si torna alla
                // pagina principale del corso o del gruppo.
                $da = $tipo;
            }

            $torna = [];

            if (is_string($get['torna'] ?? null) && strlen($get['torna']) <= self::TORNA_MAX) {
                parse_str($get['torna'], $letti);
                $torna = self::pulisci($letti);
            }

            return ['tipo' => $tipo, 'id' => (int) $grezzo, 'da' => $da, 'torna' => $torna];
        }

        return null;
    }

    /** @param array{tipo: string, id: int, da: string, torna: array<string, string>} $contesto */
    public static function indietro(array $contesto): string
    {
        $pagine = $contesto['tipo'] === 'gruppo' ? self::DA_GRUPPO : self::DA_CORSO;
        $url = str_replace('{id}', (string) $contesto['id'], $pagine[$contesto['da']]);

        return $contesto['torna'] === [] ? $url : $url . '?' . http_build_query($contesto['torna']);
    }

    /**
     * Il pezzo d'indirizzo per i file scaricati: solo il corso o il gruppo,
     * perche' da dove si viene al file non interessa.
     *
     * @param array{tipo: string, id: int}|null $contesto
     */
    public static function perScaricare(?array $contesto): string
    {
        return $contesto === null ? '' : '?' . http_build_query([$contesto['tipo'] => $contesto['id']]);
    }

    /**
     * Solo coppie nome=valore semplici: niente elenchi, niente nomi strani.
     *
     * @param array<mixed> $parametri
     * @return array<string, string>
     */
    private static function pulisci(array $parametri): array
    {
        $puliti = [];

        foreach ($parametri as $nome => $valore) {
            if (is_string($nome) && preg_match('/^[a-z_]{1,30}$/', $nome) === 1
                && (is_string($valore) || is_int($valore)) && strlen((string) $valore) <= 100) {
                $puliti[$nome] = (string) $valore;
            }
        }

        return $puliti;
    }
}
