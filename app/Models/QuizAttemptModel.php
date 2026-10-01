<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per `quiz_attempts` e `quiz_attempt_answers`.
 * I tentativi sono illimitati: vale sempre il punteggio migliore.
 */
class QuizAttemptModel
{
    /**
     * Registra un tentativo e le relative risposte in un'unica transazione.
     *
     * Una voce per **opzione scelta**, non per domanda: la scelta multipla
     * ne produce piu' d'una sulla stessa domanda. La risposta aperta ha
     * `selected_option_id` a null e il testo in `answer_text`.
     *
     * @param list<array{question_id: int, selected_option_id: int|null, answer_text: string|null, is_correct: bool}> $answers
     */
    public static function create(int $userId, int $quizId, float $scorePct, bool $passed, array $answers): int
    {
        $db = Database::connection();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare(
                'INSERT INTO quiz_attempts (user_id, quiz_id, score_pct, passed)
                 VALUES (:user_id, :quiz_id, :score_pct, :passed)'
            );
            $stmt->execute([
                'user_id' => $userId,
                'quiz_id' => $quizId,
                'score_pct' => $scorePct,
                'passed' => $passed ? 1 : 0,
            ]);

            $attemptId = (int) $db->lastInsertId();

            /*
             * Una riga per opzione scelta, non per domanda: la scelta
             * multipla ne produce piu' d'una sulla stessa domanda. La
             * risposta aperta non punta a nessuna opzione e porta il
             * proprio testo.
             */
            $answerStmt = $db->prepare(
                'INSERT INTO quiz_attempt_answers
                     (attempt_id, question_id, selected_option_id, answer_text, is_correct)
                 VALUES (:attempt_id, :question_id, :selected_option_id, :answer_text, :is_correct)'
            );

            foreach ($answers as $answer) {
                $answerStmt->execute([
                    'attempt_id' => $attemptId,
                    'question_id' => $answer['question_id'],
                    'selected_option_id' => $answer['selected_option_id'],
                    'answer_text' => $answer['answer_text'],
                    'is_correct' => $answer['is_correct'] ? 1 : 0,
                ]);
            }

            $db->commit();

            return $attemptId;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM quiz_attempts WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $attempt = $stmt->fetch();

        return $attempt ?: null;
    }

    /**
     * Tutti i tentativi di un utente su un quiz, dal piu' recente.
     */
    public static function forUserAndQuiz(int $userId, int $quizId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, score_pct, passed, attempted_at
             FROM quiz_attempts
             WHERE user_id = :user_id AND quiz_id = :quiz_id
             ORDER BY attempted_at DESC, id DESC'
        );
        $stmt->execute(['user_id' => $userId, 'quiz_id' => $quizId]);

        return $stmt->fetchAll();
    }

    public static function hasPassed(int $userId, int $quizId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM quiz_attempts
             WHERE user_id = :user_id AND quiz_id = :quiz_id AND passed = 1 LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'quiz_id' => $quizId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Miglior punteggio (e relativo esito) di un utente su un quiz.
     *
     * @return array{score_pct: float, passed: bool, attempts: int}|null
     */
    public static function bestForUserAndQuiz(int $userId, int $quizId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT MAX(score_pct) AS score_pct, MAX(passed) AS passed, COUNT(*) AS attempts
             FROM quiz_attempts
             WHERE user_id = :user_id AND quiz_id = :quiz_id'
        );
        $stmt->execute(['user_id' => $userId, 'quiz_id' => $quizId]);
        $row = $stmt->fetch();

        if (!$row || $row['attempts'] === 0 || $row['attempts'] === '0') {
            return null;
        }

        return [
            'score_pct' => (float) $row['score_pct'],
            'passed' => (bool) $row['passed'],
            'attempts' => (int) $row['attempts'],
        ];
    }

    /**
     * Id dei quiz di un corso superati dall'utente.
     *
     * @return int[]
     */
    public static function passedQuizIdsForCourse(int $userId, int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT qa.quiz_id
             FROM quiz_attempts qa
             INNER JOIN quizzes q ON q.id = qa.quiz_id
             INNER JOIN modules m ON m.id = q.module_id
             WHERE qa.user_id = :user_id AND m.course_id = :course_id AND qa.passed = 1'
        );
        $stmt->execute(['user_id' => $userId, 'course_id' => $courseId]);

        return array_map('intval', array_column($stmt->fetchAll(), 'quiz_id'));
    }

    /**
     * Risposte date in un tentativo, con testo della domanda e dell'opzione scelta.
     */
    /**
     * Le risposte di un tentativo, una riga per opzione scelta.
     *
     * `LEFT JOIN` sulle opzioni e non `INNER`: la risposta aperta non punta
     * a nessuna opzione, e con l'unione interna sparirebbe dall'elenco —
     * cioe' proprio il dato che qualcuno deve leggere.
     *
     * @return list<array{question_id: int, question_text: string, question_type: string,
     *                    option_text: ?string, answer_text: ?string, is_correct: bool}>
     */
    public static function answersForAttempt(int $attemptId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT qaa.question_id, qaa.is_correct, qaa.answer_text,
                    qq.question_text, qq.question_type, qo.option_text
             FROM quiz_attempt_answers qaa
             INNER JOIN quiz_questions qq ON qq.id = qaa.question_id
             LEFT JOIN quiz_options qo ON qo.id = qaa.selected_option_id
             WHERE qaa.attempt_id = :attempt_id
             ORDER BY qq.position, qq.id, qo.position, qo.id'
        );
        $stmt->execute(['attempt_id' => $attemptId]);

        $righe = [];

        foreach ($stmt->fetchAll() as $riga) {
            $righe[] = [
                'question_id' => (int) $riga['question_id'],
                'question_text' => (string) $riga['question_text'],
                'question_type' => (string) $riga['question_type'],
                'option_text' => $riga['option_text'] === null ? null : (string) $riga['option_text'],
                'answer_text' => $riga['answer_text'] === null ? null : (string) $riga['answer_text'],
                'is_correct' => (int) $riga['is_correct'] === 1,
            ];
        }

        return $righe;
    }

    /**
     * Le sole risposte aperte di un tentativo, una per domanda. Sono quelle
     * che nessuno puo' correggere a macchina e che qualcuno deve leggere.
     *
     * @return list<array{question_text: string, answer_text: string}>
     */
    public static function openAnswersForAttempt(int $attemptId): array
    {
        $aperte = [];

        foreach (self::answersForAttempt($attemptId) as $riga) {
            if ($riga['question_type'] !== 'open') {
                continue;
            }

            $aperte[] = [
                'question_text' => $riga['question_text'],
                'answer_text' => (string) $riga['answer_text'],
            ];
        }

        return $aperte;
    }

    /**
     * Sintesi dei tentativi di un utente su tutti i quiz di un corso (per i report).
     */
    public static function summaryForUserAndCourse(int $userId, int $courseId): array
    {
        /*
         * `last_attempt_id` serve al report per portare al dettaglio del
         * tentativo, dove si leggono le risposte aperte: senza, quel dato
         * non sarebbe raggiungibile da nessuna pagina. Si ricava con
         * GROUP_CONCAT ordinato perche' la query e' gia' raggruppata per
         * quiz, e MAX(id) prenderebbe l'identificativo piu' alto, non
         * quello del tentativo piu' recente — di solito coincidono, ma
         * «di solito» non e' una regola su cui costruire un collegamento.
         */
        // Virgolette doppie, non singole: dentro c'e' un separatore `','`
        // per GROUP_CONCAT, e in una stringa PHP a virgolette singole quel
        // carattere chiuderebbe la stringa a meta' query. Succede in
        // silenzio — l'errore arriva da PDO, lontano dalla causa.
        $stmt = Database::connection()->prepare(
            "SELECT q.id AS quiz_id, q.title AS quiz_title, q.passing_score_pct,
                    m.title AS module_title,
                    COUNT(qa.id) AS attempts,
                    MAX(qa.score_pct) AS best_score_pct,
                    MAX(qa.passed) AS passed,
                    MAX(qa.attempted_at) AS last_attempt_at,
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(qa.id ORDER BY qa.attempted_at DESC, qa.id DESC), ',', 1
                    ) AS last_attempt_id
             FROM quizzes q
             INNER JOIN modules m ON m.id = q.module_id
             LEFT JOIN quiz_attempts qa ON qa.quiz_id = q.id AND qa.user_id = :user_id
             WHERE m.course_id = :course_id
             GROUP BY q.id, q.title, q.passing_score_pct, m.title, m.position, m.id
             ORDER BY m.position, m.id"
        );
        $stmt->execute(['user_id' => $userId, 'course_id' => $courseId]);

        return $stmt->fetchAll();
    }
}
