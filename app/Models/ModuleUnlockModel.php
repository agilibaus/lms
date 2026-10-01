<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDOException;

/**
 * Chi deve ancora ricevere l'avviso di un modulo appena aperto, e chi lo ha
 * gia' ricevuto.
 *
 * La memoria del lavoro periodico del rilascio progressivo (§8.7). La regola
 * che conta sta tutta in `claim()`: la riga si scrive **prima** dell'invio.
 */
class ModuleUnlockModel
{
    /**
     * Gli avvisi da mandare: moduli gia' aperti il cui studente non e' ancora
     * stato avvisato.
     *
     * Il confronto con l'ora si fa in SQL. `is_published` esclude i corsi in
     * bozza: un modulo con una data in un corso non ancora pubblicato non
     * deve scrivere a nessuno.
     *
     * `available_from IS NOT NULL` non e' solo coerenza: e' quello che
     * impedisce il diluvio al primo giro. Un modulo senza data e' sempre
     * stato aperto e nessuno e' mai stato avvisato, quindi senza questa
     * condizione la prima esecuzione manderebbe un'email per ogni studente
     * per ogni modulo esistente. L'avviso riguarda solo i moduli che si
     * aprono davvero in un momento preciso.
     *
     * Il limite e' la cintura: se il comando resta fermo a lungo e si
     * riaccende, parte un blocco per volta invece di una raffica.
     *
     * @return array<int, array{module_id: int, user_id: int, email: string,
     *                          full_name: string, module_title: string,
     *                          course_id: int, course_title: string}>
     */
    public static function pending(int $limit = 500): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT m.id AS module_id, u.id AS user_id, u.email, u.full_name,
                    m.title AS module_title, c.id AS course_id, c.title AS course_title
             FROM modules m
             INNER JOIN courses c ON c.id = m.course_id
             INNER JOIN enrollments e ON e.course_id = c.id
             INNER JOIN users u ON u.id = e.user_id
             LEFT JOIN module_unlock_notifications n
                    ON n.module_id = m.id AND n.user_id = u.id
             WHERE m.available_from IS NOT NULL
               AND m.available_from <= NOW()
               AND c.is_published = 1
               AND n.id IS NULL
             ORDER BY m.available_from, m.id, u.id
             LIMIT ' . (int) max(1, $limit)
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Prende in carico un avviso: scrive la riga e dice se e' riuscito.
     *
     * **Prima la riga, poi l'email.** Se il processo muore nel mezzo, o se il
     * server di posta rifiuta, quell'avviso e' perduto: lo studente trovera'
     * comunque il modulo aperto rientrando. L'alternativa — mandare prima e
     * segnare dopo — fa arrivare l'avviso due, cinque, dieci volte a ogni
     * giro finito male, a tutti gli studenti insieme. Fra le due e' questo il
     * verso giusto in cui sbagliare.
     *
     * Torna `false` se la riga c'era gia': e' la chiave unica a decidere, non
     * una lettura fatta prima, cosi' due esecuzioni sovrapposte del comando
     * non mandano la stessa email due volte.
     */
    public static function claim(int $moduleId, int $userId): bool
    {
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO module_unlock_notifications (module_id, user_id)
                 VALUES (:module_id, :user_id)'
            );
            $stmt->execute(['module_id' => $moduleId, 'user_id' => $userId]);

            return true;
        } catch (PDOException) {
            // Violazione della chiave unica: qualcun altro l'ha gia' preso.
            return false;
        }
    }

}
