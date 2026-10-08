<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Le domande degli studenti al tutor e l'archivio delle risposte (07/10,
 * chiesto da Elena). Tabella `course_questions`.
 *
 * LE DECISIONI DI ELENA
 *   - lo studente fa una domanda su un modulo, o sul corso in generale;
 *   - la riceve il tutor del suo gruppo, e l'admin; senza tutor la vede
 *     solo l'admin;
 *   - il tutor la puo' correggere e la pubblica con la risposta, oppure la
 *     scarta; nessuna risposta privata;
 *   - le pubblicate le vedono tutti gli iscritti al corso, di ogni gruppo,
 *     con il nome dell'autore come l'ha scelto nel profilo, divise per
 *     modulo e con la ricerca;
 *   - una notifica al tutor per ogni domanda nuova; nessun avviso allo
 *     studente quando e' pubblicata.
 */
final class QuestionModel
{
    public const PENDING = 'pending';
    public const PUBLISHED = 'published';
    public const DISCARDED = 'discarded';

    /** Quanto puo' essere lunga una domanda, e una risposta. */
    public const MAX_QUESTION_CHARS = 2000;
    public const MAX_ANSWER_CHARS = 5000;

    /**
     * Il tutor a cui va la domanda di uno studente in un corso: il tutor del
     * gruppo attraverso cui lo studente ha il corso. Con piu' gruppi con
     * tutor diversi, il primo per nome, come per il benvenuto. Null: nessun
     * tutor, e la domanda la vede solo l'admin.
     */
    public static function tutorFor(int $courseId, int $studentId): ?int
    {
        $stmt = Database::connection()->prepare(
            'SELECT g.tutor_id
             FROM group_members gm
             INNER JOIN `groups` g ON g.id = gm.group_id
             INNER JOIN group_course_access gca ON gca.group_id = g.id
             INNER JOIN users t ON t.id = g.tutor_id
             WHERE gm.user_id = :student AND gca.course_id = :course
             ORDER BY t.full_name, t.email
             LIMIT 1'
        );
        $stmt->execute(['student' => $studentId, 'course' => $courseId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public static function create(int $courseId, ?int $moduleId, int $studentId, ?int $tutorId, string $question): int
    {
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO course_questions (course_id, module_id, student_id, tutor_id, question)
             VALUES (:c, :m, :s, :t, :q)'
        )->execute(['c' => $courseId, 'm' => $moduleId, 's' => $studentId, 't' => $tutorId, 'q' => $question]);

        return (int) $db->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM course_questions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Le domande di uno studente in un corso, dalla piu' recente, con lo
     * stato: e' «Le tue domande».
     *
     * @return list<array<string, mixed>>
     */
    public static function ofStudent(int $courseId, int $studentId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, question, status, created_at
             FROM course_questions
             WHERE course_id = :c AND student_id = :s
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute(['c' => $courseId, 's' => $studentId]);

        return $stmt->fetchAll();
    }

    /**
     * L'archivio: le domande pubblicate di un corso, nell'ordine dei moduli
     * del corso e, dentro un modulo, dalla risposta piu' recente. Quelle sul
     * corso in generale vengono per ultime. Con `$cerca` restano solo quelle
     * che lo contengono nella domanda o nella risposta.
     *
     * Con l'autore nei campi che servono a `PersonName::shown()`: il nome
     * da mostrare lo decide chi guarda.
     *
     * @return list<array<string, mixed>>
     */
    public static function published(int $courseId, string $cerca = ''): array
    {
        $sql = 'SELECT q.id, q.question, q.answer, q.module_id, q.tutor_id, q.created_at, q.answered_at,
                       m.title AS module_title, m.position AS module_position,
                       s.id AS student_id, s.first_name, s.last_name, s.full_name, s.name_display,
                       r.full_name AS answered_by_name
                FROM course_questions q
                LEFT JOIN modules m ON m.id = q.module_id
                LEFT JOIN users s ON s.id = q.student_id
                LEFT JOIN users r ON r.id = q.answered_by
                WHERE q.course_id = :c AND q.status = :st';
        $parametri = ['c' => $courseId, 'st' => self::PUBLISHED];
        $cerca = trim($cerca);

        if ($cerca !== '') {
            // Il testo cercato e' testo, non un modello: `%` e `_` si
            // cercano per quello che sono.
            $sql .= " AND (q.question LIKE :t1 ESCAPE '!' OR q.answer LIKE :t2 ESCAPE '!')";
            $like = '%' . strtr($cerca, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $parametri['t1'] = $like;
            $parametri['t2'] = $like;
        }

        $sql .= ' ORDER BY (q.module_id IS NULL), m.position, m.id, q.answered_at DESC, q.id DESC';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($parametri);

        return $stmt->fetchAll();
    }

    /**
     * Le domande in attesa per chi risponde: tutte per chi ha
     * `question.answer`, altrimenti solo quelle assegnate a lui. Con il
     * corso, il modulo, lo studente per intero (e' lo staff che guarda), il
     * gruppo attraverso cui lo studente ha quel tutor, e il tutor assegnato,
     * che l'admin deve vedere (Elena).
     *
     * @return list<array<string, mixed>>
     */
    public static function pending(int $viewerId, bool $tutte): array
    {
        $sql = "SELECT q.id, q.course_id, q.module_id, q.question, q.created_at, q.tutor_id,
                       c.title AS course_title, s.full_name AS student_name,
                       t.full_name AS tutor_name,
                       (SELECT GROUP_CONCAT(DISTINCT g.name ORDER BY g.name SEPARATOR ', ')
                        FROM group_members gm
                        INNER JOIN `groups` g ON g.id = gm.group_id
                        INNER JOIN group_course_access gca ON gca.group_id = g.id AND gca.course_id = q.course_id
                        WHERE gm.user_id = q.student_id) AS group_names
                FROM course_questions q
                INNER JOIN courses c ON c.id = q.course_id
                LEFT JOIN users s ON s.id = q.student_id
                LEFT JOIN users t ON t.id = q.tutor_id
                WHERE q.status = 'pending'";
        $parametri = [];

        if (!$tutte) {
            $sql .= ' AND q.tutor_id = :viewer';
            $parametri['viewer'] = $viewerId;
        }

        $sql .= ' ORDER BY c.title, q.created_at, q.id';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($parametri);

        return $stmt->fetchAll();
    }

    /**
     * Le domande pubblicate che chi guarda puo' correggere o togliere
     * dall'archivio (08/10): tutte per l'admin, quelle assegnate a lui per
     * il tutor, come per le domande in attesa. Divise per corso e, dentro un
     * corso, nell'ordine dell'archivio.
     *
     * @return list<array<string, mixed>>
     */
    public static function publishedFor(int $viewerId, bool $tutte): array
    {
        $sql = "SELECT q.id, q.course_id, q.module_id, q.question, q.answer, q.created_at, q.answered_at, q.tutor_id,
                       c.title AS course_title, s.full_name AS student_name, t.full_name AS tutor_name,
                       r.full_name AS answered_by_name
                FROM course_questions q
                INNER JOIN courses c ON c.id = q.course_id
                LEFT JOIN modules m ON m.id = q.module_id
                LEFT JOIN users s ON s.id = q.student_id
                LEFT JOIN users t ON t.id = q.tutor_id
                LEFT JOIN users r ON r.id = q.answered_by
                WHERE q.status = 'published'";
        $parametri = [];

        if (!$tutte) {
            $sql .= ' AND q.tutor_id = :viewer';
            $parametri['viewer'] = $viewerId;
        }

        $sql .= ' ORDER BY c.title, c.id, (q.module_id IS NULL), m.position, m.id, q.answered_at DESC, q.id DESC';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($parametri);

        return $stmt->fetchAll();
    }

    public static function pendingCount(int $viewerId, bool $tutte): int
    {
        $sql = "SELECT COUNT(*) FROM course_questions WHERE status = 'pending'";
        $parametri = [];

        if (!$tutte) {
            $sql .= ' AND tutor_id = :viewer';
            $parametri['viewer'] = $viewerId;
        }

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($parametri);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Pubblica una domanda in attesa, con il testo eventualmente corretto,
     * il modulo eventualmente cambiato e la risposta. Dice se l'ha fatto:
     * una domanda gia' pubblicata o scartata nel frattempo non si tocca.
     */
    public static function publish(int $id, string $question, ?int $moduleId, string $answer, int $answeredBy): bool
    {
        $stmt = Database::connection()->prepare(
            "UPDATE course_questions
                SET question = :q, module_id = :m, answer = :a, status = 'published',
                    answered_by = :by, answered_at = NOW()
              WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute(['q' => $question, 'm' => $moduleId, 'a' => $answer, 'by' => $answeredBy, 'id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Corregge una domanda gia' pubblicata: testo, modulo e risposta (08/10).
     * Solo se e' ancora pubblicata: una tolta nel frattempo non torna
     * nell'archivio per una modifica. Chi ha risposto e la data della
     * risposta restano quelli della pubblicazione.
     */
    public static function update(int $id, string $question, ?int $moduleId, string $answer): bool
    {
        $stmt = Database::connection()->prepare(
            "UPDATE course_questions SET question = :q, module_id = :m, answer = :a
              WHERE id = :id AND status = 'published'"
        );
        $stmt->execute(['q' => $question, 'm' => $moduleId, 'a' => $answer, 'id' => $id]);

        // `rowCount` conta le righe cambiate: salvare senza toccare niente
        // da' 0, ma non e' un errore se la domanda e' pubblicata.
        return $stmt->rowCount() === 1 || (self::find($id)['status'] ?? null) === self::PUBLISHED;
    }

    /**
     * Toglie una domanda dall'archivio (08/10): torna «Non pubblicata», come
     * una scartata, e lo studente continua a vederla fra le sue con quello
     * stato (scelta di Elena). La risposta resta salvata, e chi l'aveva data
     * pure: si cambia solo lo stato.
     */
    public static function withdraw(int $id): bool
    {
        $stmt = Database::connection()->prepare(
            "UPDATE course_questions SET status = 'discarded' WHERE id = :id AND status = 'published'"
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() === 1;
    }

    public static function discard(int $id, int $answeredBy): bool
    {
        $stmt = Database::connection()->prepare(
            "UPDATE course_questions SET status = 'discarded', answered_by = :by, answered_at = NOW()
              WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute(['by' => $answeredBy, 'id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Chi riceve la notifica di una domanda senza tutor: gli amministratori
     * attivi.
     *
     * @return list<array{email: string, full_name: string}>
     */
    public static function adminRecipients(): array
    {
        return Database::connection()->query(
            "SELECT email, full_name FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY full_name, email"
        )->fetchAll();
    }
}
