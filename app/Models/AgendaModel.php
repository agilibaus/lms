<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\LiveScope;

/**
 * Le cose con una data che riguardano **una** persona.
 *
 * Oggi sono due: gli incontri dal vivo e l'apertura dei moduli rilasciati a
 * una data (§8.7). Sono due tabelle diverse e restano due query diverse:
 * unirle con una UNION farebbe una colonna sola di cose che hanno campi
 * diversi, e il primo evento di un terzo tipo costringerebbe a rifare tutto.
 * A metterle in fila ci pensa `App\Core\Agenda`.
 *
 * IL PERIMETRO E' LA PARTE CHE CONTA. Un'agenda e' un elenco di cose che
 * esistono altrove, ed e' esattamente il genere di pagina da cui si scopre
 * per sbaglio l'esistenza di un corso a cui non si e' iscritti. Qui dentro
 * ogni query porta la propria condizione di appartenenza: l'iscrizione al
 * corso o l'appartenenza al gruppo. Non c'e' un ramo che restituisca tutto.
 */
class AgendaModel
{
    /**
     * Gli incontri dal vivo che riguardano questa persona.
     *
     * E' **la stessa regola della pagina «Sessioni live»**, e infatti e'
     * la stessa funzione: `LiveScope::elencoPer()`. Lo studente vede gli
     * incontri dei corsi a cui e' iscritto e dei gruppi di cui fa parte,
     * saltati quelli di un modulo non ancora aperto; lo staff vede quelli
     * del proprio perimetro. Due definizioni di «quali incontri sono
     * tuoi» sarebbero due occasioni di dire cose diverse, e la seconda
     * occasione se ne accorgerebbe solo chi vede una riga di troppo.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function incontriDi(int $userId): array
    {
        return LiveScope::elencoPer($userId);
    }

    /**
     * I moduli che si aprono a una data, nei corsi a cui e' iscritta.
     *
     * Si prendono anche quelli gia' aperti: in agenda «il modulo 3 si e'
     * aperto lunedi'» e' un'informazione, non un residuo. A nasconderli
     * dopo un po' ci pensa la vista, che mostra il passato richiuso.
     *
     * `available_from IS NOT NULL` esclude i moduli senza data, che sono
     * sempre aperti e quindi non sono un evento di nessun giorno.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function apertureDi(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT m.id, m.title, m.available_from, m.course_id,
                    c.title AS course_title,
                    (SELECT l.id FROM lessons l
                      WHERE l.module_id = m.id
                      ORDER BY l.position, l.id LIMIT 1) AS prima_lezione
               FROM modules m
               JOIN courses c ON c.id = m.course_id
               JOIN enrollments e ON e.course_id = m.course_id AND e.user_id = :user_id
              WHERE m.available_from IS NOT NULL
              ORDER BY m.available_from'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * L'indirizzo personale del calendario e le sue due date.
     *
     * Le date non sono un dettaglio: un indirizzo che vale come una
     * password e di cui non si sa niente non si sa nemmeno quando
     * revocarlo. «Creato il 4 ottobre, letto l'ultima volta oggi alle
     * 9:15» dice a chi guarda se serve ancora e se lo sta leggendo
     * qualcuno che non se lo aspetta.
     *
     * Restano NULL per chi aveva creato l'indirizzo prima che queste due
     * colonne esistessero: la pagina lo dice invece di inventare un
     * giorno.
     *
     * @return array{token: string, creato: ?string, usato: ?string}|null
     */
    public static function calendarioDi(int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT calendar_token, calendar_token_created_at, calendar_token_used_at
               FROM users WHERE id = :id'
        );
        $stmt->execute(['id' => $userId]);
        $riga = $stmt->fetch();

        if ($riga === false || ($riga['calendar_token'] ?? '') === '' || $riga['calendar_token'] === null) {
            return null;
        }

        return [
            'token' => (string) $riga['calendar_token'],
            'creato' => $riga['calendar_token_created_at'],
            'usato' => $riga['calendar_token_used_at'],
        ];
    }

    /**
     * Chi ha questo indirizzo, se qualcuno ce l'ha.
     *
     * Il confronto lo fa il database su una colonna UNIQUE: un token che
     * non esiste non trova nessuno, e non c'e' modo di farsi dire «esiste
     * ma non e' tuo».
     *
     * @return array<string, mixed>|null
     */
    public static function perToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $stmt = Database::connection()->prepare(
            'SELECT id, full_name, is_active FROM users WHERE calendar_token = :t LIMIT 1'
        );
        $stmt->execute(['t' => $token]);
        $utente = $stmt->fetch();

        // Un utente disattivato non ha piu' accesso alla piattaforma: non
        // puo' continuare a ricevere il proprio calendario per un link
        // lasciato nel telefono.
        if ($utente === false || (int) $utente['is_active'] !== 1) {
            return null;
        }

        return $utente;
    }

    /**
     * Crea o rigenera l'indirizzo personale, e lo restituisce.
     *
     * Rigenerare **invalida il link vecchio**: e' il motivo per cui questa
     * funzione esiste separata da «dammi il token», e perche' nel profilo
     * il comando lo dice prima di eseguirlo.
     */
    public static function rigeneraToken(int $userId): string
    {
        // 24 byte dal generatore crittografico: lo stesso che genera i
        // token di reimpostazione della password. Un token indovinabile
        // qui vorrebbe dire gli impegni di una persona letti da un altro.
        $token = bin2hex(random_bytes(24));

        // La data di creazione si scrive qui e la data d'uso si azzera:
        // rigenerare e' una revoca, e tenere l'ultima lettura del token
        // vecchio accanto a quello nuovo direbbe una cosa falsa.
        $stmt = Database::connection()->prepare(
            'UPDATE users
                SET calendar_token = :t,
                    calendar_token_created_at = NOW(),
                    calendar_token_used_at = NULL
              WHERE id = :id'
        );
        $stmt->execute(['t' => $token, 'id' => $userId]);

        return $token;
    }

    public static function dimenticaToken(int $userId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users
                SET calendar_token = NULL,
                    calendar_token_created_at = NULL,
                    calendar_token_used_at = NULL
              WHERE id = :id'
        );
        $stmt->execute(['id' => $userId]);
    }

    /**
     * Segna che il calendario e' stato letto adesso.
     *
     * Una scrittura per ogni lettura, e va bene: un programma di
     * calendario rilegge ogni qualche ora, non ogni secondo, e la riga e'
     * una sola. L'ora la mette il database con `NOW()` e non PHP: e' la
     * stessa ragione per cui le date del rilascio si confrontano in SQL
     * (§5 del promemoria).
     *
     * Non si registra **chi** ha letto — niente indirizzi, niente nomi di
     * programmi: la data basta a decidere se revocare, il resto sarebbe un
     * registro delle abitudini di una persona che nessuno ha chiesto.
     */
    public static function segnaLettura(int $userId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET calendar_token_used_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $userId]);
    }
}
