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
     * `articolo` e' l'articolo determinativo plurale: «Cerca fra **i**
     * corsi» ma «Cerca fra **gli** studenti». In italiano dipende da come
     * comincia la parola, non dal genere, quindi non si puo' ricavare da
     * `plurale` senza riscrivere la regola — e sbagliarla su un caso.
     * Dichiararlo e' una parola in piu' per sezione e nessuna eccezione da
     * indovinare.
     *
     * `cerca` e' **campo del dato => intestazione della colonna**. La
     * chiave serve a filtrare, il valore a dire a chi guarda che cosa puo'
     * scrivere nel campo di ricerca: i report hanno dati diversi, e un
     * suggerimento uguale per tutti («nome, email…») prometteva in quattro
     * casi su cinque una ricerca che non c'e'. Le intestazioni sono quelle
     * della tabella sotto, e `tests/report_test.php` verifica che lo
     * restino: sono scritte in due file, e due stringhe uguali scritte in
     * due posti divergono.
     *
     * @return array<string, array{titolo: string, singolare: string, plurale: string,
     *                             articolo: string, base: string, vuoto: string,
     *                             occhiello: string, cerca: array<string, string>}>
     */
    public static function tutte(): array
    {
        return [
            'courses' => [
                'titolo' => 'Per corso',
                'singolare' => 'corso',
                'plurale' => 'corsi',
                'articolo' => 'i',
                'base' => '/reports/courses',
                'vuoto' => 'Nessun corso.',
                'occhiello' => 'Iscritti, completamenti e certificati di ogni corso.',
                'cerca' => ['title' => 'Corso'],
            ],
            'groups' => [
                'titolo' => 'Per gruppo',
                'singolare' => 'gruppo',
                'plurale' => 'gruppi',
                'articolo' => 'i',
                'base' => '/reports/groups',
                'vuoto' => 'Nessun gruppo visibile.',
                'occhiello' => 'I membri di ogni gruppo incrociati con i corsi assegnati.',
                'cerca' => ['name' => 'Gruppo', 'tutor_name' => 'Tutor'],
            ],
            'students' => [
                'titolo' => 'Per studente',
                'singolare' => 'studente',
                'plurale' => 'studenti',
                'articolo' => 'gli',
                'base' => '/reports/students',
                'vuoto' => 'Nessuno studente visibile.',
                'occhiello' => 'Tutti i corsi di uno studente, con tentativi e punteggi.',
                'cerca' => ['full_name' => 'Studente', 'email' => 'Email'],
            ],
            'live' => [
                'titolo' => 'Per incontro dal vivo',
                'singolare' => 'incontro',
                'plurale' => 'incontri',
                'articolo' => 'gli',
                'base' => '/reports/live',
                'vuoto' => 'Nessun incontro.',
                'occhiello' => 'Le presenze, con l’origine del dato.',
                // Due campi diversi, una colonna sola: il suggerimento la
                // nomina una volta (ci pensa `suggerimento()`).
                'cerca' => [
                    'title' => 'Incontro',
                    'course_title' => 'Corso o gruppo',
                    'group_name' => 'Corso o gruppo',
                ],
            ],
            'fruizione' => [
                'titolo' => 'Fruizione dei video',
                'singolare' => 'corso con video',
                'plurale' => 'corsi con video',
                'articolo' => 'i',
                'base' => '/reports/fruizione',
                'vuoto' => 'Nessun corso ha lezioni con video.',
                'occhiello' => 'Quanta parte di ogni video hanno guardato gli studenti. '
                    . 'È il dato da rendicontare: si scarica per corso, con una colonna per lezione.',
                'cerca' => ['title' => 'Corso'],
            ],
        ];
    }

    /**
     * Il suggerimento del campo di ricerca: le colonne in cui si cerca
     * davvero, in minuscolo e senza ripetizioni.
     *
     * @param array<string, string> $cerca campo => intestazione
     */
    public static function suggerimento(array $cerca): string
    {
        $etichette = [];

        foreach ($cerca as $etichetta) {
            $minuscola = mb_strtolower($etichetta);

            if (!in_array($minuscola, $etichette, true)) {
                $etichette[] = $minuscola;
            }
        }

        return implode(', ', $etichette) . '…';
    }

    public static function esiste(string $chiave): bool
    {
        return isset(self::tutte()[$chiave]);
    }

    /**
     * Le colonne di un taglio, lette dallo stesso file che le disegna.
     *
     * Il controller deve ordinare **prima** di paginare — ordinare dopo
     * vorrebbe dire riordinare le cinquanta righe di quella pagina, cioe'
     * la risposta sbagliata — e per farlo gli serve sapere su quale campo
     * ordina ogni colonna. Quelle informazioni stanno in
     * `app/Views/reports/_colonne.php` insieme al resto della colonna.
     *
     * Leggerle di li' e' meno ordinato che averle in una classe, e lo
     * preferisco a un secondo elenco di campi tenuto a mano: due elenchi
     * della stessa cosa divergono, e qui divergere vorrebbe dire ordinare
     * una colonna sui dati di un'altra — un difetto che non si vede, perche'
     * la pagina resta piena di numeri plausibili.
     *
     * @return array<string, array{0: string, 1: string}> chiave => [campo, tipo]
     */
    public static function ordinabili(string $chiave): array
    {
        // Le funzioni che rendono le celle lo richiedono per chiudersi
        // sopra; qui non ne viene chiamata nessuna.
        $esc = static fn (?string $v): string => htmlspecialchars((string) $v);

        /** @var array<string, list<array<string, mixed>>> $tutte */
        $tutte = require dirname(__DIR__) . '/Views/reports/_colonne.php';

        $mappa = [];

        foreach ($tutte[$chiave] ?? [] as $colonna) {
            if ($colonna['chiave'] !== null && $colonna['campo'] !== null) {
                $mappa[$colonna['chiave']] = [$colonna['campo'], $colonna['tipo']];
            }
        }

        return $mappa;
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
