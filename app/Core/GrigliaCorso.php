<?php

declare(strict_types=1);

namespace App\Core;

/**
 * «Griglia del corso» (21/10, Alessandro): una riga per studente, una
 * colonna per attivita', per vedere chi e' arrivato dove e dove ci si ferma.
 *
 * LE CASELLE. Ogni casella ha uno stato:
 * - `fatto`: lezione completata, presente all'incontro, questionario superato
 *   (con il punteggio migliore: e' quello che decide il superamento);
 * - `tentato`: questionario fatto ma non superato, con il punteggio migliore;
 * - `vuoto`: lezione non completata, questionario mai fatto;
 * - `assente`: incontro gia' passato senza la sua presenza;
 * - `futuro`: incontro non ancora svolto;
 * - `non_previsto`: incontro a cui lo studente non e' atteso: di un gruppo di
 *   cui non fa parte, o di tutto il corso ma prima della sua iscrizione o
 *   dopo che l'aveva finito (25/10).
 * Solo fatto/non fatto, niente video a meta' (decisione del 21/10).
 *
 * I CONTI. Le attivita' `futuro` e `non_previsto` non contano, ne' sopra
 * ne' sotto la riga: «Tappa 2» fra due settimane non rende incompleto chi
 * ha fatto tutto il resto del modulo.
 *
 * Qui solo le regole, senza database: i dati li raccoglie
 * `GrigliaCorsoModel`.
 */
final class GrigliaCorso
{
    public const FATTO = 'fatto';
    public const TENTATO = 'tentato';
    public const VUOTO = 'vuoto';
    public const ASSENTE = 'assente';
    public const FUTURO = 'futuro';
    public const NON_PREVISTO = 'non_previsto';

    public const LEZIONE = 'lezione';
    public const INCONTRO = 'incontro';
    public const QUESTIONARIO = 'questionario';

    /** Se l'attivita' entra nei conti dello studente. */
    public static function conta(string $stato): bool
    {
        return $stato !== self::FUTURO && $stato !== self::NON_PREVISTO;
    }

    /**
     * Quante attivita' ha fatto, su quante gli toccano.
     *
     * @param list<array{stato: string}> $caselle
     * @return array{fatte: int, totale: int}
     */
    public static function conteggio(array $caselle): array
    {
        $fatte = 0;
        $totale = 0;

        foreach ($caselle as $c) {
            if (!self::conta($c['stato'])) {
                continue;
            }

            $totale++;

            if ($c['stato'] === self::FATTO) {
                $fatte++;
            }
        }

        return ['fatte' => $fatte, 'totale' => $totale];
    }

    /** Un modulo e' completo se ha fatto tutto quello che gli tocca, e qualcosa gli tocca. */
    public static function completo(array $conteggio): bool
    {
        return $conteggio['totale'] > 0 && $conteggio['fatte'] === $conteggio['totale'];
    }

    /**
     * Il modulo chiesto nell'indirizzo (`?modulo=3`, da 1), o null per la
     * vista di tutti i moduli. Un numero fuori dal corso vale come nessuno.
     */
    public static function modulo(mixed $chiesto, int $quanti): ?int
    {
        if (!is_string($chiesto) && !is_int($chiesto)) {
            return null;
        }

        $n = filter_var($chiesto, FILTER_VALIDATE_INT);

        return $n !== false && $n >= 1 && $n <= $quanti ? $n : null;
    }

    /**
     * Lo stato di un incontro per uno studente. `$atteso` viene dalla regola
     * degli incontri (`LiveSessionModel::atteso`, 25/10): chi non e' atteso
     * — un altro gruppo, o una classe di un altro periodo — non e' assente.
     */
    public static function statoIncontro(bool $presente, bool $futuro, bool $atteso): string
    {
        if (!$atteso) {
            return self::NON_PREVISTO;
        }

        if ($presente) {
            return self::FATTO;
        }

        return $futuro ? self::FUTURO : self::ASSENTE;
    }

    /** Lo stato di un questionario dal migliore dei tentativi (null: mai fatto). */
    public static function statoQuestionario(?bool $superato): string
    {
        return match ($superato) {
            null => self::VUOTO,
            true => self::FATTO,
            false => self::TENTATO,
        };
    }

    /** Il punteggio come si legge: «90%», «72,5%». */
    public static function punteggio(float $pct): string
    {
        $arrotondato = round($pct, 1);

        return ($arrotondato == (int) $arrotondato ? (string) (int) $arrotondato : str_replace('.', ',', (string) $arrotondato)) . '%';
    }

    /** L'intestazione dell'attivita' nel file scaricato: «M1 · Lezione · Benvenuto!». */
    public static function intestazione(int $modulo, string $tipo, string $titolo): string
    {
        return 'M' . $modulo . ' · ' . ucfirst($tipo) . ' · ' . $titolo;
    }
}
