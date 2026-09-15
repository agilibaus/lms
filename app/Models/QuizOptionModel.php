<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per la tabella `quiz_options`.
 */
class QuizOptionModel
{
    /**
     * Opzioni complete (incluso is_correct) — solo per admin/tutor in fase di modifica
     * o per la correzione lato server. Da non passare mai alle view dello studente.
     */
    public static function forQuestion(int $questionId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, question_id, option_text, is_correct, position
             FROM quiz_options WHERE question_id = :question_id ORDER BY position, id'
        );
        $stmt->execute(['question_id' => $questionId]);

        return $stmt->fetchAll();
    }

    /**
     * Opzioni senza il flag della risposta corretta — usate nella view di svolgimento
     * del quiz, cosi' la soluzione non finisce mai nell'HTML servito allo studente.
     */
    public static function forQuestionWithoutAnswers(int $questionId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, question_id, option_text, position
             FROM quiz_options WHERE question_id = :question_id ORDER BY position, id'
        );
        $stmt->execute(['question_id' => $questionId]);

        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM quiz_options WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $option = $stmt->fetch();

        return $option ?: null;
    }

    public static function correctOptionId(int $questionId): ?int
    {
        $stmt = Database::connection()->prepare(
            'SELECT id FROM quiz_options WHERE question_id = :question_id AND is_correct = 1 ORDER BY position, id LIMIT 1'
        );
        $stmt->execute(['question_id' => $questionId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Allinea le opzioni di una domanda a quelle inviate dal form.
     *
     * Le righe esistenti vengono aggiornate in posizione invece di essere
     * ricreate: `quiz_attempt_answers` ha una FK ON DELETE CASCADE verso
     * `quiz_options`, quindi cancellare e reinserire farebbe sparire il
     * dettaglio delle risposte gia' date dagli studenti. Vengono eliminate
     * solo le opzioni eccedenti quando il numero di risposte diminuisce.
     *
     * @param array<int, array{text: string, is_correct: bool}> $options
     */
    public static function replaceForQuestion(int $questionId, array $options): void
    {
        $db = Database::connection();
        $existing = self::forQuestion($questionId);

        $update = $db->prepare(
            'UPDATE quiz_options
             SET option_text = :option_text, is_correct = :is_correct, position = :position
             WHERE id = :id'
        );
        $insert = $db->prepare(
            'INSERT INTO quiz_options (question_id, option_text, is_correct, position)
             VALUES (:question_id, :option_text, :is_correct, :position)'
        );

        foreach (array_values($options) as $position => $option) {
            if (isset($existing[$position])) {
                $update->execute([
                    'option_text' => $option['text'],
                    'is_correct' => $option['is_correct'] ? 1 : 0,
                    'position' => $position,
                    'id' => (int) $existing[$position]['id'],
                ]);

                continue;
            }

            $insert->execute([
                'question_id' => $questionId,
                'option_text' => $option['text'],
                'is_correct' => $option['is_correct'] ? 1 : 0,
                'position' => $position,
            ]);
        }

        $surplus = array_slice($existing, count($options));

        if ($surplus !== []) {
            $delete = $db->prepare('DELETE FROM quiz_options WHERE id = :id');

            foreach ($surplus as $option) {
                $delete->execute(['id' => (int) $option['id']]);
            }
        }
    }

    /**
     * Numero di domande di un quiz prive di risposta corretta: un quiz in questo
     * stato non e' somministrabile.
     */
    public static function countQuestionsWithoutCorrectOption(int $quizId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM quiz_questions qq
             WHERE qq.quiz_id = :quiz_id
               AND NOT EXISTS (
                   SELECT 1 FROM quiz_options qo
                   WHERE qo.question_id = qq.id AND qo.is_correct = 1
               )'
        );
        $stmt->execute(['quiz_id' => $quizId]);

        return (int) $stmt->fetchColumn();
    }
}
