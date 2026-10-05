<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Mail\MailException;
use App\Core\Mail\Mailer;
use App\Models\UserModel;

/**
 * La coda degli inviti: le email con la password per gli utenti importati.
 *
 * PERCHE' A SCAGLIONI. Importando duecento persone, duecento email
 * identiche in trenta secondi sono due problemi insieme: il limite di
 * invii dell'hosting, e i filtri antispam, che quel ritmo lo riconoscono
 * per quello che e'. Venti alla volta, un quarto d'ora di distanza: le
 * duecento arrivano in due ore e mezza senza sembrare un invio di massa.
 *
 * L'ORDINE DELLE OPERAZIONI, che e' l'unica cosa difficile qui dentro:
 *
 *   1. si genera la password;
 *   2. se ne scrive **l'impronta** sull'utente;
 *   3. si manda l'email, con la password in chiaro dentro;
 *   4. **solo se l'email e' partita** l'utente esce dalla coda.
 *
 * Se la posta fallisce, quell'utente resta in coda e al giro dopo riceve
 * una password nuova. La password di adesso non l'ha mai saputa nessuno —
 * e' vissuta il tempo di comporre un messaggio che non e' partito — quindi
 * riscriverla non toglie niente a nessuno.
 *
 * Il verso opposto, togliere dalla coda e poi mandare, lascerebbe un
 * account con una password che non conosce nemmeno il suo proprietario, e
 * nessuno se ne accorgerebbe fino alla telefonata. E' la stessa scelta
 * gia' fatta per la password temporanea di un account esistente, dove pero'
 * l'ordine e' rovesciato **perche' li' c'e' una password funzionante da
 * non rovinare**: qui non c'e' niente da rovinare, e il verso giusto
 * cambia di conseguenza.
 */
class Invites
{
    /** Quanti inviti per esecuzione. Scelto da Elena: venti ogni quarto d'ora. */
    public const PER_SCAGLIONE = 20;

    /** Quando e' girato l'ultima volta il comando. */
    public const KEY_LAST_RUN = 'INVITES_LAST_RUN_AT';

    /**
     * Manda il prossimo scaglione.
     *
     * @return array{mandati: int, falliti: list<string>, restano: int}
     */
    public static function mandaScaglione(?int $quanti = null): array
    {
        $quanti ??= self::PER_SCAGLIONE;
        $mandati = 0;
        $falliti = [];

        foreach (UserModel::pendingInvites($quanti) as $utente) {
            $id = (int) $utente['id'];
            $email = (string) $utente['email'];
            $password = PasswordGenerator::genera();

            UserModel::setInvitePassword($id, $password);

            try {
                Mailer::send(Mailer::invite(
                    $email,
                    (string) $utente['full_name'],
                    $password,
                    Url::to('/login')
                ));
            } catch (MailException $e) {
                error_log('[Inviti] ' . $email . ': ' . $e->getMessage());
                $falliti[] = $email;
                continue;
            }

            UserModel::markInviteSent($id);
            $mandati++;
        }

        Settings::recordSystem(self::KEY_LAST_RUN, date('Y-m-d H:i:s'));

        return [
            'mandati' => $mandati,
            'falliti' => $falliti,
            'restano' => UserModel::countPendingInvites(),
        ];
    }
}
