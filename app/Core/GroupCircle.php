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

    /*
     * GLI ANELLI (27/11, Alessandro, variante A del mockup). Oltre 20
     * partecipanti, invece della griglia, due o tre cerchi concentrici
     * attorno al tutor, finche' c'e' lo spazio per farli stare.
     *
     * Nessun raggio fisso: gli anelli si allargano e si stringono con il
     * numero di persone. Il passo e' uno solo, in tutte e due le direzioni —
     * fra due vicini sullo stesso anello e fra un anello e il successivo — ed
     * e' lo spazio che serve a una foto, a un nome di tre righe sotto la foto
     * e a un margine. Le persone si dividono fra gli anelli in proporzione alla
     * loro lunghezza, cosi' il passo resta uguale ovunque; gli anelli sono
     * sfalsati di mezzo posto, perche' le foto non si allineino. L'anello
     * interno si apre quanto basta a contenere tutti; non scende sotto il
     * minimo che lascia libero il tutor con il suo nome.
     *
     * Si sceglie il numero di anelli piu' piccolo per cui l'anello interno
     * non resta lontano dal tutor (non piu' di un passo oltre il minimo) e il
     * disegno sta nella larghezza per cui il CSS lo accende: 52 rem con due
     * anelli, la soglia del cerchio di sempre, e 58,6 rem con tre, lo spazio
     * della pagina del gruppo su uno schermo di 1280 px con la barra laterale
     * aperta. Cosi' gli anelli tengono fino a 50 persone; oltre, la griglia.
     *
     * Le misure sono in rem, come la soglia: con un testo piu' grande
     * (Aspetto) cresce tutto insieme. Foto 58 px con due anelli e 48 con tre,
     * nomi a 0,92 rem (decisi da Alessandro sul mockup).
     */

    /** Le misure per due e per tre anelli, in rem: foto, larghezza del nome, larghezza massima. */
    public const ANELLI = [
        2 => ['foto' => 3.625, 'nome' => 7.0, 'larghezza' => 52.0],
        3 => ['foto' => 3.0, 'nome' => 6.5, 'larghezza' => 58.6],
    ];

    /** La foto del tutor (104 px) e lo spazio del suo nome e del ruolo, sotto. */
    private const TUTOR = 6.5;
    private const TUTOR_NOME = 3.75;

    /** Un nome di tre righe a 0,92 rem, con il distacco dalla foto. */
    private const NOME_ALTO = 3.125;

    /** Il margine che resta fra un nome e la foto vicina. */
    private const MARGINE = 1.0;

    /**
     * La disposizione ad anelli per questo numero di partecipanti, o null se
     * non si usa: fino a MASSIMO il cerchio di sempre, e oltre la capienza di
     * tre anelli la griglia.
     *
     * Le coordinate sono in rem dall'angolo in alto a sinistra del riquadro
     * degli anelli; le persone, nell'ordine in cui arrivano, riempiono prima
     * l'anello interno, ciascun anello dall'alto in senso orario.
     *
     * @return array{anelli: int, foto: float, nome: float, larghezza: float, altezza: float,
     *               tutor: array{x: float, y: float}, raggi: list<float>, quanti: list<int>,
     *               posizioni: list<array{x: float, y: float}>}|null
     */
    public static function anelli(int $partecipanti): ?array
    {
        if ($partecipanti <= self::MASSIMO) {
            return null;
        }

        foreach (self::ANELLI as $k => $misure) {
            $foto = $misure['foto'];
            $passo = $foto + self::NOME_ALTO + self::MARGINE;
            $minimo = self::TUTOR / 2 + self::TUTOR_NOME + $foto / 2 + 0.75;

            // Il raggio interno per cui la somma delle circonferenze contiene
            // tutti con questo passo: Σ 2π r_i ≥ n · passo, r_i = r_1 + i · passo.
            $interno = max($minimo, ($partecipanti * $passo / (2 * M_PI) - $passo * $k * ($k - 1) / 2) / $k);
            $raggi = [];

            for ($i = 0; $i < $k; $i++) {
                $raggi[] = $interno + $i * $passo;
            }

            $metaLarghezza = $raggi[$k - 1] + $misure['nome'] / 2;

            if ($interno > $minimo + $passo || 2 * $metaLarghezza > $misure['larghezza']) {
                continue;
            }

            return self::disponi($partecipanti, $k, $raggi, $foto, $misure['nome'], $metaLarghezza);
        }

        return null;
    }

    /**
     * @param list<float> $raggi
     * @return array{anelli: int, foto: float, nome: float, larghezza: float, altezza: float,
     *               tutor: array{x: float, y: float}, raggi: list<float>, quanti: list<int>,
     *               posizioni: list<array{x: float, y: float}>}
     */
    private static function disponi(int $n, int $k, array $raggi, float $foto, float $nome, float $metaLarghezza): array
    {
        // Le persone per anello, in proporzione alla lunghezza; quello che
        // l'arrotondamento lascia o prende va all'anello esterno.
        $somma = array_sum($raggi);
        $quanti = array_map(static fn (float $r): int => (int) round($n * $r / $somma), $raggi);
        $quanti[$k - 1] += $n - array_sum($quanti);

        $esterno = $raggi[$k - 1];
        $cx = $metaLarghezza;
        $cy = $esterno + $foto / 2 + 0.5;
        $posizioni = [];

        foreach ($raggi as $i => $r) {
            for ($q = 0; $q < $quanti[$i]; $q++) {
                $angolo = -M_PI / 2 + 2 * M_PI * ($q + ($i % 2) * 0.5) / $quanti[$i];
                $posizioni[] = [
                    'x' => round($cx + $r * cos($angolo), 3),
                    'y' => round($cy + $r * sin($angolo), 3),
                ];
            }
        }

        return [
            'anelli' => $k,
            'foto' => $foto,
            'nome' => $nome,
            'larghezza' => round(2 * $metaLarghezza, 3),
            'altezza' => round($cy + $esterno + $foto / 2 + self::NOME_ALTO + 0.5, 3),
            'tutor' => ['x' => round($cx, 3), 'y' => round($cy, 3)],
            'raggi' => array_map(static fn (float $r): float => round($r, 3), $raggi),
            'quanti' => $quanti,
            'posizioni' => $posizioni,
        ];
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
