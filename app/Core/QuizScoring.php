<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Come si corregge un quiz.
 *
 * Sta in una classe a se' per la stessa ragione di `WatchIntervals`: e'
 * aritmetica su dati noti, quindi si collauda dal container senza database
 * e senza browser, ed e' la parte in cui un errore fa il danno peggiore —
 * un punteggio sbagliato non si vede, si crede.
 *
 * LE QUATTRO REGOLE, e perche' sono queste.
 *
 * - **Scelta singola** e **vero/falso**: giusta se l'opzione scelta e'
 *   quella corretta. Come e' sempre stato.
 * - **Scelta multipla**: «tutto o niente». Il punto si prende selezionando
 *   **esattamente** tutte le corrette e nessuna sbagliata; tre su quattro
 *   non vale niente. Scelto da Elena il 01/10 fra questa e il punteggio
 *   parziale, insieme all'indicazione «seleziona N risposte» accanto alla
 *   domanda, che toglie l'ambiguita' su quante aspettarsene.
 * - **Aperta**: **non entra nel calcolo**, ne' al numeratore ne' al
 *   denominatore. Non e' «vale zero»: vale zero farebbe scendere il
 *   punteggio di chi ha risposto bene a tutto il resto, ed e' esattamente
 *   l'errore da non fare. La risposta si raccoglie e la legge il tutor.
 *
 * IL CASO LIMITE che non va dimenticato: un quiz di sole domande aperte non
 * ha niente da correggere. Non si puo' superare ne' fallire, e il
 * denominatore sarebbe zero. Qui vale «consegnato», cioe' superato con
 * punteggio pieno, perche' l'alternativa — bocciare chi ha risposto a tutto
 * — sarebbe assurda, e perche' con `quiz_required` un quiz impossibile da
 * superare terrebbe i moduli successivi chiusi per sempre.
 */
class QuizScoring
{
    /** I tipi che fanno punteggio. L'aperta non c'e', ed e' il punto. */
    public const TIPI_VALUTATI = ['single_choice', 'true_false', 'multiple_choice'];

    /** Tutti i tipi che una domanda puo' avere, nell'ordine del modulo. */
    public const TIPI = ['single_choice', 'multiple_choice', 'true_false', 'open'];

    public static function esiste(string $tipo): bool
    {
        return in_array($tipo, self::TIPI, true);
    }

    public static function valutata(string $tipo): bool
    {
        return in_array($tipo, self::TIPI_VALUTATI, true);
    }

    /** Quante risposte si possono scegliere: piu' d'una solo nella multipla. */
    public static function piuRisposte(string $tipo): bool
    {
        return $tipo === 'multiple_choice';
    }

    public static function haOpzioni(string $tipo): bool
    {
        return $tipo !== 'open';
    }

    public static function etichetta(string $tipo): string
    {
        return match ($tipo) {
            'single_choice' => 'Scelta singola',
            'multiple_choice' => 'Scelta multipla',
            'true_false' => 'Vero / Falso',
            'open' => 'Risposta aperta',
            default => $tipo,
        };
    }

    /**
     * Una domanda a scelta multipla e' giusta solo se le opzioni scelte sono
     * **esattamente** quelle corrette: nessuna mancante, nessuna di troppo.
     *
     * Gli identificativi si confrontano come insiemi, non come elenchi:
     * l'ordine in cui lo studente ha spuntato le caselle non significa
     * niente, e un doppione nell'invio non deve cambiare l'esito.
     *
     * @param list<int> $scelte   id delle opzioni selezionate
     * @param list<int> $corrette id delle opzioni corrette
     */
    public static function multiplaGiusta(array $scelte, array $corrette): bool
    {
        // Senza risposte corrette non c'e' niente da indovinare: la domanda
        // e' mal costruita, e si considera sbagliata invece di regalare il
        // punto a chi non seleziona niente.
        if ($corrette === []) {
            return false;
        }

        $s = array_values(array_unique(array_map('intval', $scelte)));
        $c = array_values(array_unique(array_map('intval', $corrette)));

        sort($s);
        sort($c);

        return $s === $c;
    }

    /**
     * Il punteggio in percentuale sulle sole domande valutate.
     *
     * @param  int $giuste    domande valutate indovinate
     * @param  int $valutate  domande valutate in totale
     * @return float percentuale con due decimali; 100 quando non c'e' niente
     *               da valutare (vedi il caso limite in testa alla classe)
     */
    public static function percentuale(int $giuste, int $valutate): float
    {
        if ($valutate <= 0) {
            return 100.0;
        }

        $giuste = max(0, min($giuste, $valutate));

        return round($giuste * 100 / $valutate, 2);
    }

    /**
     * Superato o no. Con zero domande valutate e' sempre superato, ed e'
     * voluto: un quiz di sole domande aperte si consegna, non si supera, e
     * non deve tenere chiusi i moduli successivi.
     */
    public static function superato(float $percentuale, int $sogliaPct, int $valutate): bool
    {
        if ($valutate <= 0) {
            return true;
        }

        return $percentuale >= (float) $sogliaPct;
    }

    /**
     * Quante domande di un quiz fanno punteggio.
     *
     * @param list<array{question_type?: string}> $domande
     */
    public static function conteggioValutate(array $domande): int
    {
        $n = 0;

        foreach ($domande as $domanda) {
            if (self::valutata((string) ($domanda['question_type'] ?? 'single_choice'))) {
                $n++;
            }
        }

        return $n;
    }
}
