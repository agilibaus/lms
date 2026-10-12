<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * «Il giro del cerchio» (12/10, Alessandro): la domanda al gruppo e le
 * risposte dei partecipanti.
 *
 * La domanda **aperta** e' l'ultima posta al gruppo: resta aperta finche' non
 * ne arriva una nuova. Le precedenti, con le loro risposte, sono l'archivio.
 * Una risposta per persona e per domanda (la chiave unica): riscriverla la
 * sostituisce. Una risposta nascosta dal tutor non si cancella: la vede solo
 * chi l'ha scritta (e chi modera, per poterla rimettere).
 *
 * Le regole su chi puo' fare che cosa stanno in `App\Core\GiroDelCerchio`.
 */
class GroupQuestionModel
{
    /** Quanto puo' essere lunga una domanda. */
    public const MAX_DOMANDA = 300;

    /** Quanto puo' essere lunga una risposta: una o due righe. */
    public const MAX_RISPOSTA = 200;

    private const SELECT_DOMANDA =
        'SELECT q.id, q.group_id, q.author_id, q.question, q.created_at,
                u.first_name AS author_first_name, u.full_name AS author_name, u.avatar_path AS author_avatar
         FROM group_questions q
         LEFT JOIN users u ON u.id = q.author_id';

    /** La domanda aperta del gruppo, con il suo autore. */
    public static function current(int $groupId): ?array
    {
        $stmt = Database::connection()->prepare(
            self::SELECT_DOMANDA . ' WHERE q.group_id = :g ORDER BY q.created_at DESC, q.id DESC LIMIT 1'
        );
        $stmt->execute(['g' => $groupId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Le domande passate: tutte tranne l'aperta, dalla piu' recente.
     *
     * @return list<array<string, mixed>>
     */
    public static function archive(int $groupId): array
    {
        $aperta = self::current($groupId);

        if ($aperta === null) {
            return [];
        }

        $stmt = Database::connection()->prepare(
            self::SELECT_DOMANDA . ' WHERE q.group_id = :g AND q.id <> :aperta ORDER BY q.created_at DESC, q.id DESC'
        );
        $stmt->execute(['g' => $groupId, 'aperta' => (int) $aperta['id']]);

        return $stmt->fetchAll();
    }

    public static function countArchive(int $groupId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM group_questions WHERE group_id = :g');
        $stmt->execute(['g' => $groupId]);

        return max(0, (int) $stmt->fetchColumn() - 1);
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(self::SELECT_DOMANDA . ' WHERE q.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function create(int $groupId, int $authorId, string $question): int
    {
        Database::connection()->prepare(
            'INSERT INTO group_questions (group_id, author_id, question) VALUES (:g, :a, :q)'
        )->execute(['g' => $groupId, 'a' => $authorId, 'q' => $question]);

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Le risposte a una domanda, con chi le ha scritte (i campi che servono
     * a mostrare il nome come ciascuno ha scelto, e la foto). Dalla piu'
     * recente; la vista mette la propria per prima.
     *
     * @return list<array<string, mixed>>
     */
    public static function answers(int $questionId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT a.id, a.question_id, a.user_id, a.answer, a.created_at, a.updated_at, a.hidden_at,
                    u.id AS persona_id, u.full_name, u.first_name, u.last_name, u.name_display, u.avatar_path
             FROM group_answers a
             INNER JOIN users u ON u.id = a.user_id
             WHERE a.question_id = :q
             ORDER BY COALESCE(a.updated_at, a.created_at) DESC, a.id DESC'
        );
        $stmt->execute(['q' => $questionId]);

        return $stmt->fetchAll();
    }

    public static function findAnswer(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT a.*, q.group_id FROM group_answers a INNER JOIN group_questions q ON q.id = a.question_id WHERE a.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function answerOf(int $questionId, int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM group_answers WHERE question_id = :q AND user_id = :u'
        );
        $stmt->execute(['q' => $questionId, 'u' => $userId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Scrive o riscrive la risposta di una persona. */
    public static function saveAnswer(int $questionId, int $userId, string $answer): void
    {
        Database::connection()->prepare(
            'INSERT INTO group_answers (question_id, user_id, answer) VALUES (:q, :u, :a)
             ON DUPLICATE KEY UPDATE answer = VALUES(answer), updated_at = NOW()'
        )->execute(['q' => $questionId, 'u' => $userId, 'a' => $answer]);
    }

    public static function deleteAnswer(int $questionId, int $userId): void
    {
        Database::connection()->prepare('DELETE FROM group_answers WHERE question_id = :q AND user_id = :u')
            ->execute(['q' => $questionId, 'u' => $userId]);
    }

    /** Nasconde una risposta (`$by` chi modera) o la rimette (`null`). */
    public static function setHidden(int $answerId, ?int $by): void
    {
        Database::connection()->prepare(
            'UPDATE group_answers SET hidden_at = IF(:nascondi = 1, NOW(), NULL), hidden_by = :by WHERE id = :id'
        )->execute(['nascondi' => $by !== null ? 1 : 0, 'by' => $by, 'id' => $answerId]);
    }
}
