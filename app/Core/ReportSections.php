<?php

declare(strict_types=1);

namespace App\Core;

/**
 * I cinque tagli dei report, descritti **una volta sola**.
 *
 * PERCHE' ESISTE. Fino alla 0085 la pagina Report stampava cinque tabelle
 * intere una sotto l'altra: 22.042 px di altezza, perche' l'elenco degli
 * studenti ne aveva 605 senza ne' ricerca ne' paginazione. Chi arrivava
 * doveva scorrere ventidue schermi per vedere che tagli esistevano.
 *
 * Adesso l'indice **sceglie** e gli elenchi stanno in pagine loro. Ma
 * indice ed elenchi parlano delle stesse cose, e descriverle due volte
 * significa vederle divergere al primo cambiamento: la colonna aggiunta a
 * un elenco e dimenticata nell'indice, il titolo cambiato in un posto solo.
 * Da qui vengono entrambi.
 *
 * LE COLONNE PORTANO IL PROPRIO TIPO. `testo` o `numero` non e' una
 * questione di aspetto: i numeri vanno a destra, in cifre a larghezza fissa
 * e in colonne che cadono **negli stessi punti in tutte le tabelle**. Era
 * il disordine principale — nella prima tabella i tre conteggi cominciavano
 * a 611, 740 e 917 px, quindi le cifre non formavano colonne e non si
 * potevano confrontare con l'occhio.
 */
class ReportSections
{
    /** Quante righe per pagina negli elenchi. */
    public const PER_PAGINA = 50;

    /**
     * I cinque tagli. `chiave` finisce nell'indirizzo, `dati` dice al
     * controller quale insieme passare.
     *
     * @return array<string, array{titolo: string, singolare: string, plurale: string,
     *                             base: string, vuoto: string, occhiello: string,
     *                             cerca: string[]}>
     */
    public static function tutte(): array
    {
        return [
            'courses' => [
                'titolo' => 'Per corso',
                'singolare' => 'corso',
                'plurale' => 'corsi',
                'base' => '/reports/courses',
                'vuoto' => 'Nessun corso.',
                'occhiello' => 'Iscritti, completamenti e certificati di ogni corso.',
                'cerca' => ['title'],
            ],
            'groups' => [
                'titolo' => 'Per gruppo',
                'singolare' => 'gruppo',
                'plurale' => 'gruppi',
                'base' => '/reports/groups',
                'vuoto' => 'Nessun gruppo visibile.',
                'occhiello' => 'I membri di ogni gruppo incrociati con i corsi assegnati.',
                'cerca' => ['name', 'tutor_name'],
            ],
            'students' => [
                'titolo' => 'Per studente',
                'singolare' => 'studente',
                'plurale' => 'studenti',
                'base' => '/reports/students',
                'vuoto' => 'Nessuno studente visibile.',
                'occhiello' => 'Tutti i corsi di uno studente, con tentativi e punteggi.',
                'cerca' => ['full_name', 'email'],
            ],
            'live' => [
                'titolo' => 'Per incontro dal vivo',
                'singolare' => 'incontro',
                'plurale' => 'incontri',
                'base' => '/reports/live',
                'vuoto' => 'Nessun incontro.',
                'occhiello' => 'Le presenze, con l’origine del dato.',
                'cerca' => ['title', 'course_title', 'group_name'],
            ],
            'fruizione' => [
                'titolo' => 'Fruizione dei video',
                'singolare' => 'corso con video',
                'plurale' => 'corsi con video',
                'base' => '/reports/fruizione',
                'vuoto' => 'Nessun corso ha lezioni con video.',
                'occhiello' => 'Quanta parte di ogni video hanno guardato gli studenti. '
                    . 'È il dato da rendicontare: si scarica per corso, con una colonna per lezione.',
                'cerca' => ['title'],
            ],
        ];
    }

    public static function esiste(string $chiave): bool
    {
        return isset(self::tutte()[$chiave]);
    }

    /**
     * Le righe che corrispondono al testo cercato.
     *
     * Ricerca semplice, senza indici ne' punteggi: un confronto senza
     * maiuscole sui campi che la sezione dichiara. Per seicento righe in
     * memoria e' istantanea, e chiedere al database una LIKE per ogni
     * sezione vorrebbe dire cinque query nuove per risolvere un problema
     * che non c'e'. Se un giorno gli studenti saranno decine di migliaia,
     * questa funzione e' il punto da cambiare.
     *
     * @param array<int, array<string, mixed>> $righe
     * @param string[] $campi
     * @return array<int, array<string, mixed>>
     */
    public static function filtra(array $righe, array $campi, string $cerca): array
    {
        $cerca = trim($cerca);

        if ($cerca === '') {
            return $righe;
        }

        $ago = mb_strtolower($cerca);

        return array_values(array_filter($righe, static function (array $riga) use ($campi, $ago): bool {
            foreach ($campi as $campo) {
                $valore = $riga[$campo] ?? null;

                if (is_string($valore) && str_contains(mb_strtolower($valore), $ago)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * La fetta di pagina chiesta, e quante pagine ci sono in tutto.
     *
     * La pagina si corregge invece di dare errore: un indirizzo con
     * `?pagina=99` su un elenco che ne ha tre porta all'ultima, non a una
     * pagina vuota che sembra un difetto.
     *
     * @param array<int, array<string, mixed>> $righe
     * @return array{righe: array<int, array<string, mixed>>, pagina: int, pagine: int, totale: int}
     */
    public static function pagina(array $righe, int $pagina): array
    {
        $totale = count($righe);
        $pagine = max(1, (int) ceil($totale / self::PER_PAGINA));
        $pagina = max(1, min($pagina, $pagine));

        return [
            'righe' => array_slice($righe, ($pagina - 1) * self::PER_PAGINA, self::PER_PAGINA),
            'pagina' => $pagina,
            'pagine' => $pagine,
            'totale' => $totale,
        ];
    }
}
