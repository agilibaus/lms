<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per la tabella `quiz_questions`.
 */
class QuizQuestionModel
{
    public static function forQuiz(int $quizId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, quiz_id, question_text, question_type, position
             FROM quiz_questions WHERE quiz_id = :quiz_id ORDER BY position, id'
        );
        $stmt->execute(['quiz_id' => $quizId]);

        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM quiz_questions WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $question = $stmt->fetch();

        return $question ?: null;
    }

    public static function create(int $quizId, string $text, string $type): int
    {
        $db = Database::connection();

        $stmt = $db->prepare(
            'INSERT INTO quiz_questions (quiz_id, question_text, question_type, position)
             VALUES (:quiz_id, :question_text, :question_type, :position)'
        );
        $stmt->execute([
            'quiz_id' => $quizId,
            'question_text' => $text,
            'question_type' => $type,
            'position' => self::nextPosition($quizId),
        ]);

        return (int) $db->lastInsertId();
    }

    public static function update(int $id, string $text, string $type): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE quiz_questions SET question_text = :question_text, question_type = :question_type WHERE id = :id'
        );
        $stmt->execute(['question_text' => $text, 'question_type' => $type, 'id' => $id]);
    }

    public static function delete(int $id): void
    {
        // Le opzioni collegate seguono via FK ON DELETE CASCADE.
        $stmt = Database::connection()->prepare('DELETE FROM quiz_questions WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Sposta una domanda di un posto su o giu' dentro il suo quiz.
     *
     * Stesso schema di `LessonModel::move()`, e per le stesse ragioni: il
     * vicino e' la riga adiacente **nell'ordine di visualizzazione**, non
     * quella con posizione +/- 1, perche' dopo un'eliminazione le posizioni
     * hanno dei buchi; e se due righe condividono la stessa posizione si
     * rinumera, altrimenti lo scambio non si vedrebbe.
     */
    public static function move(int $id, string $direction): void
    {
        $riga = self::find($id);

        if ($riga === null) {
            return;
        }

        $db = Database::connection();

        $confronto = $direction === 'up' ? '<' : '>';
        $ordine = $direction === 'up' ? 'DESC' : 'ASC';

        $stmt = $db->prepare(
            'SELECT id, position FROM quiz_questions
             WHERE quiz_id = :quiz_id AND (position, id) ' . $confronto . ' (:position, :id)
             ORDER BY position ' . $ordine . ', id ' . $ordine . ' LIMIT 1'
        );
        $stmt->execute([
            'quiz_id' => (int) $riga['quiz_id'],
            'position' => (int) $riga['position'],
            'id' => $id,
        ]);
        $vicino = $stmt->fetch();

        if (!$vicino) {
            return; // gia' in cima o in fondo
        }

        if ((int) $vicino['position'] === (int) $riga['position']) {
            self::renumber((int) $riga['quiz_id']);
            $riga = self::find($id);
            $vicino = self::find((int) $vicino['id']);
        }

        // Rilette dopo la rinumerazione: se nel frattempo una e' stata
        // eliminata non c'e' piu' niente da scambiare.
        if ($riga === null || $vicino === null) {
            return;
        }

        $update = $db->prepare('UPDATE quiz_questions SET position = :position WHERE id = :id');
        $update->execute(['position' => (int) $vicino['position'], 'id' => $id]);
        $update->execute(['position' => (int) $riga['position'], 'id' => (int) $vicino['id']]);
    }

    /** Posizioni consecutive da 0, mantenendo l'ordine attuale. */
    private static function renumber(int $quizId): void
    {
        $db = Database::connection();
        $update = $db->prepare('UPDATE quiz_questions SET position = :position WHERE id = :id');
        $position = 0;

        foreach (self::forQuiz($quizId) as $riga) {
            $update->execute(['position' => $position++, 'id' => (int) $riga['id']]);
        }
    }

    private static function nextPosition(int $quizId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COALESCE(MAX(position), -1) + 1 FROM quiz_questions WHERE quiz_id = :quiz_id'
        );
        $stmt->execute(['quiz_id' => $quizId]);

        return (int) $stmt->fetchColumn();
    }
}
