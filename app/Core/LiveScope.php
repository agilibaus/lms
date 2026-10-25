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
 *     tutor gli incontri dei gruppi di cui e' tutor (26/10: un incontro con
 *     un gruppo e' del suo tutor anche se ha un modulo; quelli di tutto il
 *     corso sono dell'admin). E' quella che decide se compaiono i comandi
 *     di modifica.
 *   - `visibile()`   — «lo posso aprire»: se e' nel mio elenco. Dal 17/10;
 *     prima lo staff apriva qualunque incontro, link Meet compreso, anche
 *     quelli che il suo elenco non mostrava.
 *   - `elencoPer()`  — l'elenco che ne esce per chi sta guardando: l'admin
 *     tutto, il tutor gli incontri dei suoi gruppi (26/10), l'assistente
 *     quelli dei gruppi dei suoi tutor, in sola lettura; gli studenti gli
 *     incontri di cui sono destinatari, meno quelli di un modulo non ancora
 *     aperto (§8.7).
 */
class LiveScope
{
    public static function gestibile(array $session): bool
    {
        if (Auth::hasRole('admin')) {
            return true;
        }

        // Un incontro con un gruppo e' del gruppo anche se ha un modulo
        // (26/10, conflitto 2): lo gestisce il tutor di quel gruppo, non il
        // tutor di un'altra classe dello stesso corso. Uno di tutto il corso
        // lo gestisce chi puo' modificare il corso.
        if (!empty($session['group_id'])) {
            return self::gruppoMio((int) $session['group_id']);
        }

        return !empty($session['module_id']) && isset($session['course_id'])
            && CourseRights::canEdit((int) $session['course_id']);
    }

    /**
     * Si apre un incontro se e' nel proprio elenco: la pagina, l'ingresso in
     * riunione e l'elenco dicono la stessa cosa (17/10). Prima qui lo staff
     * passava sempre — `course.edit` o il ruolo — e un tutor apriva
     * l'incontro del gruppo di un collega, con il link Meet, scrivendo
     * l'indirizzo a mano.
     *
     * Per lo studente non cambia niente: il suo elenco e' gia' «destinatario
     * e modulo aperto». Un incontro legato a un modulo chiuso e' chiuso con
     * lui: altrimenti il link Meet del modulo di dicembre sarebbe
     * raggiungibile a ottobre scrivendo l'indirizzo a mano.
     */
    public static function visibile(array $session): bool
    {
        $id = (int) $session['id'];

        foreach (self::elencoDiUtente((int) Auth::id()) as $mio) {
            if ((int) $mio['id'] === $id) {
                return true;
            }
        }

        return false;
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

        // Il tutor vede gli incontri dei suoi gruppi, e solo quelli (26/10,
        // Alessandro, conflitto 2): non quelli del gruppo di un'altra classe
        // dello stesso corso, e nemmeno quelli di tutto il corso, che sono
        // dell'admin. L'assistente vede gli stessi dei tutor che affianca, in
        // sola lettura (conflitto 6): ci entra, non li gestisce.
        if ($ruolo === 'tutor' || $ruolo === 'assistente') {
            $tutor = $ruolo === 'tutor' ? [$userId] : UserModel::tutorIdsForAssistant($userId);
            $gruppi = [];

            foreach ($tutor as $t) {
                foreach (GroupModel::forTutor($t) as $g) {
                    $gruppi[] = (int) $g['id'];
                }
            }

            return array_values(array_filter(
                LiveSessionModel::all(),
                static fn (array $s): bool => !empty($s['group_id']) && in_array((int) $s['group_id'], $gruppi, true)
            ));
        }

        // Gli studenti: gli incontri di cui sono destinatari, meno
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
