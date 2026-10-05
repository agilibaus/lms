<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\AgendaModel;

/**
 * L'agenda di una persona: le cose con una data, messe in fila.
 *
 * COSA FA, e cosa non fa. Qui dentro non si decide **chi** vede cosa — se
 * ne occupa `AgendaModel`, dove ogni query porta la propria condizione di
 * appartenenza. Qui si prendono eventi di tipi diversi, si riducono a una
 * forma sola e si raggruppano come li legge una persona.
 *
 * LA FORMA UNICA di un evento:
 *
 *   tipo       'incontro' | 'apertura'
 *   titolo     cosa succede
 *   inizio     DateTimeImmutable
 *   fine       DateTimeImmutable|null  (un'apertura e' un istante)
 *   contesto   il corso o il gruppo, cioe' «dove» succede
 *   url        dove si va cliccando
 *   id         per l'indirizzo del singolo .ics
 *   apribile   solo gli incontri: si puo' entrare adesso
 *   in_corso   solo gli incontri: e' gia' cominciato e non e' finito
 *
 * Un terzo tipo di evento — una scadenza dei quiz, il giorno che non
 * esiste ancora — si aggiunge qui e compare in tutte e due le viste e nel
 * calendario esterno senza toccare altro.
 *
 * LE ORE. Le date arrivano dal database come stringhe («2026-10-04
 * 18:30:00») e si leggono con il fuso dell'applicazione, lo stesso che PHP
 * ha impostato in `config/config.php` e che MySQL usa per `NOW()`. E' la
 * trappola §5 del promemoria: confrontare un'ora letta con un fuso e una
 * scritta con un altro da' differenze di ore che si notano solo al cambio
 * dell'ora legale. Nel file .ics gli orari escono in UTC, perche' li' il
 * fuso di chi legge non lo sappiamo.
 */
class Agenda
{
    public const INCONTRO = 'incontro';
    public const APERTURA = 'apertura';

    /**
     * Tutti gli eventi di una persona, dal piu' vicino nel tempo.
     *
     * @return list<array<string, mixed>>
     */
    public static function eventiDi(int $userId): array
    {
        $eventi = [];

        foreach (AgendaModel::incontriDi($userId) as $incontro) {
            $inizio = self::momento((string) $incontro['starts_at']);

            if ($inizio === null) {
                continue;
            }

            $eventi[] = [
                'tipo' => self::INCONTRO,
                'id' => (int) $incontro['id'],
                'titolo' => (string) $incontro['title'],
                'inizio' => $inizio,
                'fine' => self::momento((string) ($incontro['ends_at'] ?? '')),
                // Dalla query, non ricalcolati qui: la finestra d'ingresso
                // e' definita una volta sola in
                // `LiveSessionModel::FINESTRA_SELECT`, e a calcolarla e'
                // il database, che e' l'orologio giusto (§5).
                'apribile' => (bool) ($incontro['joinable'] ?? false),
                'in_corso' => (bool) ($incontro['started'] ?? false),
                'contesto' => (string) ($incontro['course_title'] ?? $incontro['group_name'] ?? ''),
                'url' => '/live/' . (int) $incontro['id'],
            ];
        }

        foreach (AgendaModel::apertureDi($userId) as $modulo) {
            $inizio = self::momento((string) $modulo['available_from']);

            if ($inizio === null) {
                continue;
            }

            $eventi[] = [
                'tipo' => self::APERTURA,
                'id' => (int) $modulo['id'],
                'titolo' => (string) $modulo['title'],
                'inizio' => $inizio,
                'fine' => null,
                'contesto' => (string) $modulo['course_title'],
                // Al corso e non alla lezione: prima della data la lezione
                // risponderebbe 403, e un collegamento che si sa gia' che
                // sara' rifiutato e' peggio di nessun collegamento.
                'url' => '/courses/' . (int) $modulo['course_id'],
            ];
        }

        usort($eventi, static fn (array $a, array $b): int => $a['inizio'] <=> $b['inizio']);

        return $eventi;
    }

    /**
     * Gli eventi divisi come li legge una persona: quelli di adesso prima,
     * il passato in fondo.
     *
     * I gruppi sono quattro e sono **relativi a oggi**, non al mese: «la
     * settimana prossima» e' l'informazione che si cerca aprendo un'agenda,
     * e un elenco diviso per mese la nasconderebbe in mezzo alle altre.
     *
     * @param list<array<string, mixed>> $eventi
     * @return array{oggi: list<array<string, mixed>>, settimana: list<array<string, mixed>>,
     *               prossimi: list<array<string, mixed>>, passati: list<array<string, mixed>>}
     */
    public static function raggruppa(array $eventi, ?\DateTimeImmutable $adesso = null): array
    {
        $adesso ??= new \DateTimeImmutable('now');
        $fineOggi = $adesso->setTime(23, 59, 59);
        // Sette giorni, non «fino a domenica»: di domenica pomeriggio
        // «questa settimana» sarebbe vuota e «la prossima» piena, che e' il
        // contrario di quello che serve sapere.
        $fineSettimana = $fineOggi->modify('+7 days');

        $gruppi = ['oggi' => [], 'settimana' => [], 'prossimi' => [], 'passati' => []];

        foreach ($eventi as $evento) {
            // Un incontro gia' cominciato ma non ancora finito resta fra
            // quelli di oggi: e' il momento in cui serve di piu'.
            $riferimento = $evento['fine'] ?? $evento['inizio'];

            if ($riferimento < $adesso) {
                $gruppi['passati'][] = $evento;
            } elseif ($evento['inizio'] <= $fineOggi) {
                $gruppi['oggi'][] = $evento;
            } elseif ($evento['inizio'] <= $fineSettimana) {
                $gruppi['settimana'][] = $evento;
            } else {
                $gruppi['prossimi'][] = $evento;
            }
        }

        // Il passato si legge a ritroso: l'ultima cosa successa per prima.
        $gruppi['passati'] = array_reverse($gruppi['passati']);

        return $gruppi;
    }

    /**
     * Le settimane di un mese, ciascuna di sette giorni, con gli eventi di
     * ogni giorno.
     *
     * La griglia comincia **di lunedi'** e comprende i giorni di orlo del
     * mese prima e di quello dopo, perche' una griglia con le prime caselle
     * vuote si legge peggio di una che mostra il 29 e il 30 in grigio.
     *
     * @param list<array<string, mixed>> $eventi
     * @return list<list<array{giorno: \DateTimeImmutable, fuori: bool, eventi: list<array<string, mixed>>}>>
     */
    public static function griglia(\DateTimeImmutable $mese, array $eventi): array
    {
        $primo = $mese->modify('first day of this month')->setTime(0, 0);
        $ultimo = $mese->modify('last day of this month')->setTime(23, 59, 59);

        // `N` da' 1 per lunedi': sottraendone uno si arretra al lunedi'
        // della settimana in cui cade il primo del mese.
        $inizio = $primo->modify('-' . ((int) $primo->format('N') - 1) . ' days');

        $perGiorno = [];

        foreach ($eventi as $evento) {
            $perGiorno[$evento['inizio']->format('Y-m-d')][] = $evento;
        }

        $settimane = [];
        $giorno = $inizio;

        // Finche' non si e' superato l'ultimo giorno del mese, a settimane
        // intere: un mese ne occupa quattro, cinque o sei.
        while ($giorno <= $ultimo || (int) $giorno->format('N') !== 1) {
            $settimana = [];

            for ($i = 0; $i < 7; $i++) {
                $chiave = $giorno->format('Y-m-d');

                $settimana[] = [
                    'giorno' => $giorno,
                    'fuori' => $giorno->format('Y-m') !== $primo->format('Y-m'),
                    'eventi' => $perGiorno[$chiave] ?? [],
                ];

                $giorno = $giorno->modify('+1 day');
            }

            $settimane[] = $settimana;

            if ($giorno > $ultimo) {
                break;
            }
        }

        return $settimane;
    }

    /**
     * Il mese chiesto nell'indirizzo, o quello corrente.
     *
     * Quello che arriva da fuori o e' un «AAAA-MM» valido o viene ignorato:
     * `DateTimeImmutable` accetterebbe anche «next tuesday» e «+30 years»,
     * e un mese fuori scala vorrebbe dire costruire una griglia enorme.
     */
    public static function meseChiesto(?string $valore, ?\DateTimeImmutable $adesso = null): \DateTimeImmutable
    {
        $adesso ??= new \DateTimeImmutable('now');
        $corrente = $adesso->modify('first day of this month')->setTime(0, 0);

        // Il mese dev'essere fra 01 e 12: «2026-13» passerebbe un
        // controllo sulla sola forma, e `createFromFormat` lo
        // trasformerebbe in gennaio 2027 senza dire niente.
        if ($valore === null || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $valore) !== 1) {
            return $corrente;
        }

        $mese = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $valore . '-01 00:00:00');

        if ($mese === false) {
            return $corrente;
        }

        // Dieci anni avanti e indietro: oltre non c'e' niente da vedere, e
        // un indirizzo con l'anno 9999 diventa una pagina vuota con mille
        // caselle invece di un errore.
        $minimo = $corrente->modify('-10 years');
        $massimo = $corrente->modify('+10 years');

        if ($mese < $minimo || $mese > $massimo) {
            return $corrente;
        }

        return $mese->setTime(0, 0);
    }

    /**
     * I nomi dei mesi in italiano: `strftime` non c'e' piu' e `date()` e'
     * in inglese.
     *
     * Con l'iniziale maiuscola perche' qui il nome del mese e' un titolo —
     * «Ottobre 2026» in cima alla griglia — e non una parola in mezzo a una
     * frase. Si usa `mb_convert_case` e non `ucfirst`, che su una lettera
     * accentata lavorerebbe sul primo **byte**: oggi nessun mese italiano
     * comincia per accentata, ma la funzione non sa di essere usata solo
     * per l'italiano.
     */
    public static function nomeMese(\DateTimeImmutable $mese): string
    {
        $nomi = [
            1 => 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno',
            'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre',
        ];

        $nome = $nomi[(int) $mese->format('n')];

        return mb_convert_case(mb_substr($nome, 0, 1), MB_CASE_UPPER)
            . mb_substr($nome, 1) . ' ' . $mese->format('Y');
    }

    /**
     * Come si chiama un tipo di evento, a parole.
     *
     * Sta qui e non nella vista perche' la stessa etichetta compare in due
     * punti — sotto al titolo nell'elenco, e nel testo che un lettore di
     * schermo annuncia nella griglia del mese — e scritta due volte e'
     * destinata a divergere: e' gia' successo con l'iniziale maiuscola,
     * che andava messa in due posti.
     *
     * Maiuscola perche' l'etichetta sta da sola sotto al titolo, come una
     * voce di legenda, non in mezzo a una frase.
     */
    public static function etichettaTipo(string $tipo): string
    {
        return $tipo === self::INCONTRO ? 'Incontro dal vivo' : 'Apertura di un modulo';
    }

    /** Lunedi'…domenica, nell'ordine della griglia. */
    public static function nomiGiorni(): array
    {
        return ['lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato', 'domenica'];
    }

    private static function momento(string $valore): ?\DateTimeImmutable
    {
        if (trim($valore) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($valore);
        } catch (\Exception) {
            return null;
        }
    }
}
