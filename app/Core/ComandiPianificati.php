<?php

declare(strict_types=1);

namespace App\Core;

/**
 * I comandi che il server lancia da solo, e se stanno girando (17/11,
 * Alessandro).
 *
 * Un cron fermo e' silenzioso: i moduli si aprono lo stesso, ma le email
 * non partono e gli utenti importati restano senza password. Ogni comando
 * lascia detto quando ha girato l'ultima volta (anche quando non aveva
 * niente da fare), in una chiave di sistema delle impostazioni; qui si
 * confronta quella data con quanto il comando puo' mancare prima di dirlo
 * fermo.
 *
 * GLI STATI:
 * - «regolare»: l'ultima esecuzione e' entro la soglia;
 * - «fermo»: e' piu' vecchia della soglia;
 * - «mai partito»: non c'e' nessuna esecuzione registrata.
 *
 * LE SOGLIE (decise da Alessandro): 30 minuti per avvisi e inviti, che
 * girano ogni 5 e ogni 15 minuti; 2 giorni per il rilascio dei moduli, che
 * gira una volta al giorno. Un giro saltato ogni tanto non accende niente.
 *
 * Qui solo i conti, senza database ne' orologio: la data di adesso la passa
 * chi chiama, cosi' le soglie si provano da sole.
 */
final class ComandiPianificati
{
    public const REGOLARE = 'regolare';
    public const FERMO = 'fermo';
    public const MAI = 'mai';

    /**
     * @var list<array{chiave: string, nome: string, file: string, cosa: string, frequenza: string, soglia: int, solo_con_sorveglianza?: bool}>
     */
    public const COMANDI = [
        [
            'chiave' => 'AVVISI_LAST_RUN_AT',
            'nome' => 'Avvisi',
            'file' => 'bin/avvisi',
            'cosa' => 'Le conferme delle iscrizioni, i promemoria degli incontri e la coda delle email',
            'frequenza' => 'ogni 5 minuti',
            'soglia' => 30 * 60,
        ],
        [
            'chiave' => 'INVITES_LAST_RUN_AT',
            'nome' => 'Inviti',
            'file' => 'bin/invita-utenti',
            'cosa' => 'La password agli utenti importati, a scaglioni',
            'frequenza' => 'ogni 15 minuti',
            'soglia' => 30 * 60,
        ],
        [
            'chiave' => 'DRIP_LAST_RUN_AT',
            'nome' => 'Rilascio dei moduli',
            'file' => 'bin/rilascio-moduli',
            'cosa' => 'L’email agli studenti quando si apre un modulo',
            'frequenza' => 'ogni giorno',
            'soglia' => 2 * 24 * 60 * 60,
        ],
        // Solo con la sorveglianza esterna attiva (23/11): senza chiave il
        // comando non fa niente, e su un'installazione che non la usa non
        // deve risultare «mai partito».
        [
            'chiave' => 'SORVEGLIA_LAST_RUN_AT',
            'nome' => 'Sorveglianza',
            'file' => 'bin/sorveglia',
            'cosa' => 'Il controllo del sito e dello spazio, per la sorveglianza esterna',
            'frequenza' => 'ogni 5 minuti',
            'soglia' => 30 * 60,
            'solo_con_sorveglianza' => true,
        ],
    ];

    /**
     * Lo stato di un comando. Una data illeggibile vale come «mai partito»:
     * non dice quando ha girato. Una data nel futuro (orologi diversi fra
     * database e PHP) vale come appena eseguito.
     */
    public static function stato(?string $ultima, int $soglia, int $adesso): string
    {
        $quando = self::istante($ultima);

        if ($quando === null) {
            return self::MAI;
        }

        return $adesso - $quando > $soglia ? self::FERMO : self::REGOLARE;
    }

    /**
     * I comandi con la loro ultima esecuzione e il loro stato.
     *
     * @param callable(string): ?string $leggi legge una chiave delle impostazioni
     * @return list<array<string, mixed>>
     */
    public static function elenco(callable $leggi, int $adesso, bool $conSorveglianza = false): array
    {
        $elenco = [];

        foreach (self::COMANDI as $comando) {
            if (!empty($comando['solo_con_sorveglianza']) && !$conSorveglianza) {
                continue;
            }

            $ultima = $leggi($comando['chiave']);
            $elenco[] = $comando + [
                'ultima' => self::istante($ultima) === null ? null : $ultima,
                'stato' => self::stato($ultima, $comando['soglia'], $adesso),
            ];
        }

        return $elenco;
    }

    /**
     * Quanti comandi sono da controllare: fermi o mai partiti.
     *
     * @param callable(string): ?string $leggi
     */
    public static function daControllare(callable $leggi, int $adesso, bool $conSorveglianza = false): int
    {
        return count(array_filter(
            self::elenco($leggi, $adesso, $conSorveglianza),
            static fn (array $c): bool => $c['stato'] !== self::REGOLARE
        ));
    }

    /** «adesso», «1 minuto fa», «3 ore fa», «5 giorni fa». */
    public static function quantoFa(string $ultima, int $adesso): string
    {
        $quando = self::istante($ultima);

        if ($quando === null) {
            return '';
        }

        $secondi = max(0, $adesso - $quando);

        if ($secondi < 60) {
            return 'adesso';
        }

        foreach ([[86400, 'giorno', 'giorni'], [3600, 'ora', 'ore'], [60, 'minuto', 'minuti']] as [$unita, $uno, $tanti]) {
            if ($secondi >= $unita) {
                $n = intdiv($secondi, $unita);

                return $n . ' ' . ($n === 1 ? $uno : $tanti) . ' fa';
            }
        }

        return 'adesso';
    }

    private static function istante(?string $data): ?int
    {
        if ($data === null || trim($data) === '') {
            return null;
        }

        $t = strtotime($data);

        return $t === false ? null : $t;
    }
}
