<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Gli intervalli di video guardati: unione, taglio e controlli di
 * plausibilita'.
 *
 * Perche' esiste come classe a se'. Questa e' l'unica parte della
 * tracciatura che si puo' collaudare dal container: non tocca il database,
 * non tocca il browser, e' aritmetica su coppie di numeri. Il resto —
 * che il player mandi davvero gli eventi giusti — si prova solo con un
 * video vero (pistacchio-lms.md Sezione 7.1).
 *
 * Perche' intervalli e non un totale. Un totale si gonfia riguardando dieci
 * volte lo stesso minuto: direbbe «ha guardato 50 minuti» di una lezione da
 * 25. Gli intervalli dicono quanta lezione e' stata vista davvero, che e' il
 * dato da rendicontare.
 *
 * `end` e' escluso: [0, 10) e [10, 20) si uniscono in [0, 20) senza contare
 * due volte il secondo 10.
 *
 * ATTENZIONE A COSA NON FA. I numeri arrivano dal JavaScript dello studente,
 * che chiunque puo' modificare dagli strumenti per sviluppatori. I controlli
 * qui dentro rendono inefficace la falsificazione ingenua — non esistono dieci
 * minuti guardati in due minuti di orologio, ne' un video guardato oltre la
 * propria durata — ma **non rendono il dato infalsificabile**, e nessun
 * controllo lato server puo' farlo, perche' il dato nasce dalla parte
 * sbagliata della rete. Chi legge il report deve saperlo.
 *
 * @phpstan-type Intervallo array{0: int, 1: int}
 */
class WatchIntervals
{
    /**
     * Intervalli piu' corti di tanto non si registrano: sono il rumore di un
     * `timeupdate` isolato o di un salto della barra, non lezione guardata.
     */
    public const MIN_SECONDS = 2;

    /**
     * Margine sulla plausibilita' temporale. Il video non puo' avanzare piu'
     * in fretta dell'orologio, ma la velocita' di riproduzione arriva a 2x
     * sui player, e fra il momento in cui il browser misura e quello in cui
     * la richiesta arriva passa del tempo: senza margine si scarterebbe
     * traffico onesto.
     */
    public const MAX_SPEED = 2.5;
    public const SPEED_GRACE_SECONDS = 30;

    /**
     * Mette in ordine, taglia agli estremi leciti, butta via le briciole e
     * unisce quello che si sovrappone o si tocca.
     *
     * @param  list<array{0: int|float, 1: int|float}> $intervalli
     * @param  int|null $durata durata del video; null quando non la sappiamo
     * @return list<array{0: int, 1: int}> in ordine e senza sovrapposizioni
     */
    public static function merge(array $intervalli, ?int $durata = null): array
    {
        $puliti = [];

        foreach ($intervalli as $intervallo) {
            $inizio = (int) floor((float) $intervallo[0]);
            $fine = (int) ceil((float) $intervallo[1]);

            // Un negativo non e' un secondo di video: e' un errore di calcolo
            // o qualcuno che prova a spostare l'origine.
            $inizio = max(0, $inizio);
            $fine = max(0, $fine);

            if ($durata !== null && $durata > 0) {
                $inizio = min($inizio, $durata);
                $fine = min($fine, $durata);
            }

            if ($fine - $inizio < self::MIN_SECONDS) {
                continue;
            }

            $puliti[] = [$inizio, $fine];
        }

        if ($puliti === []) {
            return [];
        }

        usort($puliti, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        $uniti = [];
        $corrente = array_shift($puliti);

        foreach ($puliti as [$inizio, $fine]) {
            if ($inizio <= $corrente[1]) {
                // Si sovrappone o si tocca: diventano uno solo.
                $corrente[1] = max($corrente[1], $fine);
                continue;
            }

            $uniti[] = $corrente;
            $corrente = [$inizio, $fine];
        }

        $uniti[] = $corrente;

        return $uniti;
    }

    /**
     * Quanti secondi di lezione sono stati visti: la somma degli intervalli
     * gia' uniti, quindi senza doppioni.
     *
     * @param list<array{0: int, 1: int}> $intervalli
     */
    public static function total(array $intervalli): int
    {
        $totale = 0;

        foreach ($intervalli as [$inizio, $fine]) {
            $totale += max(0, $fine - $inizio);
        }

        return $totale;
    }

    /**
     * La percentuale di lezione vista, arrotondata all'intero e mai oltre 100.
     * Senza durata non c'e' percentuale: meglio niente che un numero inventato
     * su un denominatore che non si conosce.
     *
     * @param list<array{0: int, 1: int}> $intervalli
     */
    public static function percentage(array $intervalli, ?int $durata): ?int
    {
        if ($durata === null || $durata <= 0) {
            return null;
        }

        return (int) min(100, round(self::total($intervalli) * 100 / $durata));
    }

    /**
     * Il tetto di video che si puo' aver guardato in un certo tempo di
     * orologio. Serve a scartare un invio che dichiara piu' lezione di
     * quanta ne sia potuta passare fra una scrittura e l'altra.
     */
    public static function plausibleCeiling(int $secondiTrascorsi): int
    {
        $secondiTrascorsi = max(0, $secondiTrascorsi);

        return (int) floor($secondiTrascorsi * self::MAX_SPEED) + self::SPEED_GRACE_SECONDS;
    }

    /**
     * Taglia gli intervalli in arrivo a quanto e' plausibile nel tempo
     * trascorso dall'ultima scrittura. Non li scarta tutti: tiene i primi
     * fino al tetto, perche' un invio esagerato spesso e' comunque
     * l'ingrandimento di una visione vera.
     *
     * @param  list<array{0: int, 1: int}> $intervalli gia' passati da merge()
     * @return list<array{0: int, 1: int}>
     */
    public static function capToElapsed(array $intervalli, int $secondiTrascorsi): array
    {
        $tetto = self::plausibleCeiling($secondiTrascorsi);
        $rimasti = $tetto;
        $tenuti = [];

        foreach ($intervalli as [$inizio, $fine]) {
            if ($rimasti <= 0) {
                break;
            }

            $lunghezza = $fine - $inizio;

            if ($lunghezza <= $rimasti) {
                $tenuti[] = [$inizio, $fine];
                $rimasti -= $lunghezza;
                continue;
            }

            $tenuti[] = [$inizio, $inizio + $rimasti];
            break;
        }

        return $tenuti;
    }

    /**
     * Legge gli intervalli come arrivano dalla richiesta: un JSON di coppie.
     * Tutto cio' che non e' una coppia di numeri viene ignorato in silenzio —
     * il corpo della richiesta lo scrive il browser, quindi qui dentro puo'
     * esserci qualsiasi cosa, e un errore fatale non e' la risposta giusta.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function fromJson(?string $json, ?int $durata = null): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        try {
            $letto = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($letto)) {
            return [];
        }

        $coppie = [];

        // Un invio smisurato non deve poter far lavorare il server a vuoto:
        // dopo un certo numero di coppie l'unione non aggiunge informazione.
        foreach (array_slice($letto, 0, 500) as $voce) {
            if (!is_array($voce) || !isset($voce[0], $voce[1])) {
                continue;
            }

            if (!is_numeric($voce[0]) || !is_numeric($voce[1])) {
                continue;
            }

            $coppie[] = [(float) $voce[0], (float) $voce[1]];
        }

        return self::merge($coppie, $durata);
    }
}
