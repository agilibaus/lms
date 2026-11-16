<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Il ricalcolo dei voti di un questionario (16/11, Alessandro).
 *
 * Quando un questionario ha gia' dei tentativi, le domande non si aggiungono
 * e non si eliminano, il loro tipo non cambia e le opzioni non si aggiungono
 * ne' si tolgono. Si possono correggere i testi, cambiare le risposte
 * corrette e la soglia: in quei due casi i tentativi gia' svolti si
 * ricalcolano, prima mostrando che cosa cambia, poi salvando insieme la
 * modifica e i voti nuovi.
 *
 * Qui solo i conti, senza database: dai tentativi con le loro scelte, le
 * risposte corrette di ogni domanda e la soglia, il punteggio e l'esito di
 * ogni tentativo; poi il riepilogo per la pagina di conferma.
 *
 * LE REGOLE SONO QUELLE DELLA CONSEGNA (`QuizController::submit`): una domanda
 * valutata e' giusta se la scelta e' corretta, per la multipla «tutto o
 * niente»; le aperte stanno fuori dal calcolo. Ogni tentativo si ricalcola
 * sulle domande a cui ha risposto: un tentativo vecchio, di quando il
 * questionario aveva altre domande, resta sulle sue.
 *
 * I questionari H5P non passano di qui: il punteggio lo da' l'esercizio.
 */
final class Ricalcolo
{
    /**
     * Il punteggio e l'esito di ogni tentativo con le corrette e la soglia
     * date.
     *
     * @param array<int, array{user_id: int, score_pct: float, passed: bool,
     *                          risposte: array<int, array{tipo: string, scelte: list<int>}>}> $tentativi
     * @param array<int, list<int>> $corrette per domanda, gli id delle opzioni corrette
     * @return array<int, array{score_pct: float, passed: bool, giuste: array<int, bool>}>
     */
    public static function esiti(array $tentativi, array $corrette, int $soglia): array
    {
        $esiti = [];

        foreach ($tentativi as $id => $tentativo) {
            $valutate = 0;
            $indovinate = 0;
            $giuste = [];

            foreach ($tentativo['risposte'] as $domanda => $risposta) {
                if (!QuizScoring::valutata($risposta['tipo'])) {
                    continue;
                }

                $valutate++;
                $giusteDomanda = $corrette[$domanda] ?? [];
                $scelte = $risposta['scelte'];

                $ok = QuizScoring::piuRisposte($risposta['tipo'])
                    ? QuizScoring::multiplaGiusta($scelte, $giusteDomanda)
                    : count($scelte) === 1 && in_array($scelte[0], $giusteDomanda, true);

                $giuste[$domanda] = $ok;
                $indovinate += $ok ? 1 : 0;
            }

            $punteggio = QuizScoring::percentuale($indovinate, $valutate);

            $esiti[$id] = [
                'score_pct' => $punteggio,
                'passed' => QuizScoring::superato($punteggio, $soglia, $valutate),
                'giuste' => $giuste,
            ];
        }

        return $esiti;
    }

    /**
     * Che cosa cambierebbe: i tentativi e gli studenti toccati, chi non
     * supera piu' e chi supera ora, e il miglior risultato di ogni studente
     * per cui cambia.
     *
     * @param array<int, array{user_id: int, score_pct: float, passed: bool, risposte: array}> $tentativi
     * @param array<int, array{score_pct: float, passed: bool, giuste: array<int, bool>}> $esiti
     * @return array{tentativi: list<int>, studenti: list<int>, perdono: list<int>, superano: list<int>,
     *               righe: list<array{user_id: int, prima: float, prima_superato: bool, dopo: float, dopo_superato: bool}>}
     */
    public static function riepilogo(array $tentativi, array $esiti): array
    {
        $cambiati = [];
        $studenti = [];
        $migliori = [];

        foreach ($tentativi as $id => $tentativo) {
            $nuovo = $esiti[$id];
            $utente = $tentativo['user_id'];

            if (self::diverso($tentativo['score_pct'], $nuovo['score_pct']) || $tentativo['passed'] !== $nuovo['passed']) {
                $cambiati[] = $id;
                $studenti[$utente] = true;
            }

            $m = $migliori[$utente] ?? ['prima' => -1.0, 'prima_superato' => false, 'dopo' => -1.0, 'dopo_superato' => false];
            $m['prima'] = max($m['prima'], $tentativo['score_pct']);
            $m['prima_superato'] = $m['prima_superato'] || $tentativo['passed'];
            $m['dopo'] = max($m['dopo'], $nuovo['score_pct']);
            $m['dopo_superato'] = $m['dopo_superato'] || $nuovo['passed'];
            $migliori[$utente] = $m;
        }

        $perdono = [];
        $superano = [];
        $righe = [];

        foreach ($migliori as $utente => $m) {
            if ($m['prima_superato'] && !$m['dopo_superato']) {
                $perdono[] = $utente;
            }

            if (!$m['prima_superato'] && $m['dopo_superato']) {
                $superano[] = $utente;
            }

            if (self::diverso($m['prima'], $m['dopo']) || $m['prima_superato'] !== $m['dopo_superato']) {
                $righe[] = ['user_id' => $utente] + $m;
            }
        }

        return [
            'tentativi' => $cambiati,
            'studenti' => array_keys($studenti),
            'perdono' => $perdono,
            'superano' => $superano,
            'righe' => $righe,
        ];
    }

    /** I punteggi stanno in tabella con due decimali. */
    private static function diverso(float $a, float $b): bool
    {
        return abs($a - $b) >= 0.005;
    }
}
