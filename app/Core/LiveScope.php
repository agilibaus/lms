<?php

declare(strict_types=1);

namespace App\Core;

use App\Auth\Auth;
use App\Auth\CourseRights;
use App\Models\GroupModel;
use App\Models\LiveSessionModel;
use App\Models\UserModel;

/**
 * Chi vede quale incontro dal vivo.
 *
 * PERCHE' E' USCITO DAL CONTROLLER. Queste tre decisioni stavano dentro a
 * `LiveSessionController` come metodi privati, e andavano bene finche' gli
 * incontri si guardavano da una pagina sola. Con l'agenda le pagine sono
 * due, e una regola di visibilita' scritta in due posti e' una regola che
 * prima o poi dice due cose diverse: la pagina stretta mostra qualcosa che
 * quella larga nasconde, e nessuno se ne accorge finche' non e' un
 * problema. Qui c'e' una definizione sola, e le due pagine la chiamano.
 *
 * LE TRE DECISIONI, che non sono la stessa cosa:
 *
 *   - `gestibile()`  — «e' roba mia da organizzare»: l'admin tutto, il
 *     tutor i corsi che puo' modificare e i gruppi di cui e' tutor. E'
 *     quella che decide se compaiono i comandi di modifica.
 *   - `visibile()`   — «lo posso aprire»: lo staff si', lo studente solo
 *     se e' fra i destinatari **e** il modulo e' gia' aperto (§8.7).
 *   - `elencoPer()`  — l'elenco che ne esce per chi sta guardando.
 */
class LiveScope
{
    public static function gestibile(array $session): bool
    {
        if (Auth::hasRole('admin')) {
            return true;
        }

        if (
            !empty($session['module_id']) && isset($session['course_id'])
            && CourseRights::canEdit((int) $session['course_id'])
        ) {
            return true;
        }

        return !empty($session['group_id']) && self::gruppoMio((int) $session['group_id']);
    }

    public static function visibile(array $session): bool
    {
        if (Auth::can('course.edit') || Auth::hasRole('admin', 'tutor', 'assistente')) {
            return true;
        }

        if (!LiveSessionModel::isParticipant((int) $session['id'], (int) Auth::id())) {
            return false;
        }

        // Un incontro legato a un modulo chiuso e' chiuso con lui: altrimenti
        // il link Meet del modulo di dicembre sarebbe raggiungibile a ottobre
        // scrivendo l'indirizzo a mano. Gli incontri rivolti a un gruppo e non
        // a un modulo non hanno un modulo da cui dipendere: restano visibili.
        if (!empty($session['module_id'])) {
            return !CourseAccess::isModuleLocked((int) Auth::id(), (int) $session['module_id']);
        }

        return true;
    }

    public static function gruppoMio(int $groupId): bool
    {
        if (Auth::hasRole('admin')) {
            return true;
        }

        $group = GroupModel::find($groupId);

        return $group !== null && (int) ($group['tutor_id'] ?? 0) === (int) Auth::id();
    }

    /**
     * Gli incontri che questa persona puo' vedere.
     *
     * Due rami perche' sono due domande diverse: lo staff parte da tutti
     * gli incontri e tiene quelli del proprio perimetro; lo studente parte
     * dai propri e scarta quelli di un modulo non ancora aperto. L'elenco
     * mostra quello che si puo' aprire davvero — un incontro che al clic
     * darebbe 403 e' peggio di un elenco piu' corto.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function elencoPer(int $userId): array
    {
        return self::elencoDiUtente($userId);
    }

    /**
     * Lo stesso elenco, calcolato **senza la sessione**.
     *
     * PERCHE' ESISTE. Il calendario sottoscritto lo rilegge un programma,
     * ogni tanto, senza nessuno davanti: li' `Auth` non c'e'. Le regole
     * qui sopra guardano chi e' in sessione, e chiamate senza sessione
     * rispondono «nessuno» — cioe' un calendario vuoto, che e' la risposta
     * sbagliata piu' difficile da notare, perche' sembra solo che non ci
     * sia niente in programma. Visto misurando: il primo feed conteneva un
     * evento invece di tre.
     *
     * Quindi il ruolo si legge dal database invece che dalla sessione, e
     * la regola e' la stessa di sempre — tanto che la pagina «Sessioni
     * live» ora passa di qui anche lei: **una definizione sola**, usata
     * dalle due pagine e dal calendario.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function elencoDiUtente(int $userId): array
    {
        $utente = UserModel::find($userId);

        if ($utente === null || (int) $utente['is_active'] !== 1) {
            return [];
        }

        $ruolo = (string) ($utente['role'] ?? 'studente');

        if ($ruolo === 'admin') {
            return array_values(LiveSessionModel::all());
        }

        if ($ruolo === 'tutor') {
            $corsi = CourseRights::courseIdsFor($userId);
            $gruppi = array_map(
                static fn (array $g): int => (int) $g['id'],
                GroupModel::forTutor($userId)
            );

            return array_values(array_filter(
                LiveSessionModel::all(),
                static function (array $s) use ($corsi, $gruppi): bool {
                    if (!empty($s['course_id']) && in_array((int) $s['course_id'], $corsi, true)) {
                        return true;
                    }

                    return !empty($s['group_id']) && in_array((int) $s['group_id'], $gruppi, true);
                }
            ));
        }

        // Studenti e assistenti: gli incontri di cui sono destinatari, meno
        // quelli di un modulo non ancora aperto **per loro**. Il blocco si
        // chiede con l'identificativo esplicito, non con quello in
        // sessione, per lo stesso motivo di tutto il resto qui dentro.
        return array_values(array_filter(
            LiveSessionModel::forUser($userId),
            static function (array $s) use ($userId): bool {
                if (empty($s['module_id'])) {
                    return true;
                }

                return !CourseAccess::isModuleLocked($userId, (int) $s['module_id']);
            }
        ));
    }
}
