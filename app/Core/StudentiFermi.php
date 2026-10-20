<?php

declare(strict_types=1);

namespace App\Core;

/**
 * «Studenti fermi» (20/10, Alessandro): chi non avanza in un corso, per
 * contattarlo prima che lasci.
 *
 * LA REGOLA. Uno studente e' fermo in un corso se non ha attivita' nel
 * corso da almeno N giorni (7, 14 o 30; la pagina si apre su 14), oppure
 * se non l'ha mai iniziato ed e' iscritto da almeno N giorni. Attivita' e'
 * tutto cio' che lascia una data: una lezione completata, un video
 * guardato, un questionario, la presenza a un incontro del corso, una
 * domanda all'esperto, una risorsa o un racconto nella Bacheca. I corsi
 * completati non contano.
 *
 * LO STATO dice al tutor che cosa sta succedendo:
 * - «non ha mai iniziato»: nessuna attivita' dall'iscrizione;
 * - «entra ma non avanza»: ha aperto il corso negli ultimi N giorni
 *   (`enrollments.last_visit_at`, `Presenza`) ma non ha fatto niente;
 * - «non entra piu'»: non apre il corso da almeno N giorni.
 *
 * Qui solo i conti, senza database: i dati li raccoglie
 * `StudentiFermiModel`.
 */
final class StudentiFermi
{
    public const SOGLIE = [7, 14, 30];
    public const SOGLIA_INIZIALE = 14;

    public const MAI = 'mai';
    public const ENTRA = 'entra';
    public const ESCE = 'esce';

    public static function soglia(mixed $chiesta): int
    {
        $n = (int) $chiesta;

        return in_array($n, self::SOGLIE, true) ? $n : self::SOGLIA_INIZIALE;
    }

    /** I giorni interi fra una data e adesso (0 se e' oggi o nel futuro). */
    public static function giorni(string $data, \DateTimeImmutable $adesso): int
    {
        $da = new \DateTimeImmutable(substr($data, 0, 10));
        $a = new \DateTimeImmutable($adesso->format('Y-m-d'));

        return max(0, (int) $da->diff($a)->format('%r%a'));
    }

    /**
     * Fermo o no, e come.
     *
     * @return array{fermo: bool, giorni: int, stato: string}
     */
    public static function valuta(?string $ultimaAttivita, string $iscrittoIl, ?string $ultimaVisita, int $soglia, \DateTimeImmutable $adesso): array
    {
        $giorni = self::giorni($ultimaAttivita ?? $iscrittoIl, $adesso);

        if ($ultimaAttivita === null) {
            $stato = self::MAI;
        } elseif ($ultimaVisita !== null && self::giorni($ultimaVisita, $adesso) < $soglia) {
            $stato = self::ENTRA;
        } else {
            $stato = self::ESCE;
        }

        return ['fermo' => $giorni >= $soglia, 'giorni' => $giorni, 'stato' => $stato];
    }

    public static function etichettaStato(string $stato): string
    {
        return match ($stato) {
            self::MAI => 'non ha mai iniziato',
            self::ENTRA => 'entra ma non avanza',
            default => 'non entra più',
        };
    }

    /** Dove si ordina lo stato: dal meno grave al piu' grave. */
    public static function ordineStato(string $stato): int
    {
        return match ($stato) {
            self::MAI => 0,
            self::ENTRA => 1,
            default => 2,
        };
    }

    /**
     * Che cosa ha fatto per ultimo, senza verbo (21/10, Alessandro): «video
     * «Benvenuto»», non «ha guardato il video…». La colonna si legge di
     * corsa, e il tipo di attivita' basta a dire che cosa e' successo.
     */
    public static function descrivi(string $tipo, string $titolo): string
    {
        return match ($tipo) {
            'lezione' => 'lezione «' . $titolo . '»',
            'video' => 'video «' . $titolo . '»',
            'questionario' => 'questionario «' . $titolo . '»',
            'incontro' => 'incontro «' . $titolo . '»',
            'domanda' => 'domanda all\'esperto',
            'risorsa' => 'risorsa in Bacheca «' . $titolo . '»',
            'racconto' => 'racconto in Bacheca «' . $titolo . '»',
            default => $titolo,
        };
    }

    /** «oggi», «ieri», «da 5 giorni». */
    public static function quando(int $giorni): string
    {
        return match ($giorni) {
            0 => 'oggi',
            1 => 'ieri',
            default => 'da ' . $giorni . ' giorni',
        };
    }
}
