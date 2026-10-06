<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Dove stanno i partecipanti nel cerchio della pagina di un gruppo.
 *
 * Il disegno l'ha deciso Elena il 06/10: su computer le foto in cerchio, il
 * tutor al centro e i nomi rivolti verso l'esterno; sul telefono una griglia
 * con il nome sotto la foto; e **oltre 20 partecipanti la griglia anche su
 * computer**, perche' i nomi cominciano a sovrapporsi.
 *
 * Qui c'e' solo la geometria, senza database e senza HTML, cosi' si prova da
 * sola (`tests/cerchio_test.php`). La vista stampa le coordinate come
 * variabili CSS; se il cerchio non si usa — troppi partecipanti, poco
 * spazio, un browser senza query di contenitore — le coordinate restano
 * scritte e nessuno le legge.
 *
 * Le coordinate sono in percentuale del quadrato che contiene il cerchio:
 * (50, 50) e' il centro, dove sta il tutor.
 */
final class GroupCircle
{
    /** Oltre questo numero di partecipanti si usa la griglia anche su computer. */
    public const MASSIMO = 20;

    /**
     * Raggio in percentuale del lato. Non 50: la foto e' centrata sul suo
     * punto, e a 50 meta' foto uscirebbe dal quadrato.
     */
    public const RAGGIO = 42.0;

    public static function usaCerchio(int $partecipanti): bool
    {
        return $partecipanti >= 1 && $partecipanti <= self::MASSIMO;
    }

    /**
     * Una posizione per partecipante, nell'ordine in cui arrivano: il primo
     * in alto, poi in senso orario.
     *
     * `lato` dice dove va il nome perche' stia «verso l'esterno»: di fianco
     * sui due lati del cerchio, sopra in alto e sotto in basso. Di fianco
     * anche in alto non funziona: li' i vicini stanno accanto, e il nome
     * finirebbe sopra la foto del vicino.
     *
     * @return list<array{x: float, y: float, lato: string}>
     */
    public static function posizioni(int $partecipanti): array
    {
        $posizioni = [];

        for ($i = 0; $i < $partecipanti; $i++) {
            $angolo = -M_PI / 2 + 2 * M_PI * $i / $partecipanti;
            $cos = cos($angolo);
            $sin = sin($angolo);

            $posizioni[] = [
                'x' => round(50 + self::RAGGIO * $cos, 2),
                'y' => round(50 + self::RAGGIO * $sin, 2),
                'lato' => self::lato($cos, $sin),
            ];
        }

        return $posizioni;
    }

    private static function lato(float $cos, float $sin): string
    {
        // Mezzo vuol dire 30 gradi dalla verticale: una fascia in alto e
        // una in basso in cui il nome sta sopra o sotto, il resto di fianco.
        if (abs($cos) >= 0.5) {
            return $cos > 0 ? 'destra' : 'sinistra';
        }

        return $sin < 0 ? 'sopra' : 'sotto';
    }
}
