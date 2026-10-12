<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Mail\Mailer;
use App\Models\EnrollmentModel;

/**
 * Le email che parte `bin/avvisi`, ogni quarto d'ora (12/10).
 *
 * Qui stanno le email che non possono partire nella pagina che le causa:
 *
 *   - **«Iscrizione confermata» per le iscrizioni fatte dallo staff**: a mano
 *     dalla scheda del corso, entrando in un gruppo, con un corso assegnato
 *     a un gruppo intero. Un gruppo di cento persone ne manderebbe cento da
 *     una pagina sola, oltre il limite di invii del provider: qui ne partono
 *     `PER_GIRO` per volta, come gli inviti (venti ogni quarto d'ora, scelta
 *     di Elena).
 *
 * La regola e' quella del rilascio dei moduli: **prima la riga, poi
 * l'email**. Una conferma si toglie dalla coda e solo dopo si manda: se la
 * posta rifiuta, quella conferma e' perduta (lo studente trova comunque il
 * corso entrando), ma due giri sovrapposti non mandano la stessa email due
 * volte.
 */
final class Avvisi
{
    /** Quante conferme per giro. */
    public const PER_GIRO = 20;

    public const ULTIMO_GIRO = 'AVVISI_LAST_RUN_AT';

    /**
     * Manda le conferme in coda. Con `$prova` le elenca soltanto, senza
     * mandare e senza toccare la coda.
     *
     * @return array{mandate: int, fallite: list<string>, prova: list<string>}
     */
    public static function conferme(int $quante = self::PER_GIRO, bool $prova = false): array
    {
        $mandate = 0;
        $fallite = [];
        $elenco = [];

        foreach (EnrollmentModel::conferme($quante) as $riga) {
            if ($prova) {
                $elenco[] = $riga['email'] . ' ← «' . $riga['course_title'] . '»';
                continue;
            }

            if (!EnrollmentModel::prendiConferma((int) $riga['id'])) {
                continue;
            }

            $messaggio = Mailer::enrollmentConfirmed(
                (string) $riga['email'],
                (string) $riga['full_name'],
                (string) $riga['course_title'],
                Url::to('/courses/' . (int) $riga['course_id'])
            );

            if (Mailer::sendQuietly($messaggio)) {
                $mandate++;
            } else {
                $fallite[] = (string) $riga['email'];
            }
        }

        return ['mandate' => $mandate, 'fallite' => $fallite, 'prova' => $elenco];
    }
}
