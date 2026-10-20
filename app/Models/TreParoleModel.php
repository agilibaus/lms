<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\TreParole;

/**
 * I dati di «Tre parole» (21/10): quando il corso le chiede, le parole di
 * uno studente, quelle di un modulo per chi le legge. Le regole stanno in
 * `App\Core\TreParole`.
 */
final class TreParoleModel
{
    /**
     * Accende o spegne la richiesta per un corso. Accendendola si segna da
     * quando (se era gia' accesa la data resta quella); spegnendola si
     * cancella, e una riaccensione riparte da quel momento.
     */
    public static function imposta(int $courseId, bool $chiedi): void
    {
        Database::connection()->prepare(
            'UPDATE courses SET three_words_since = ' . ($chiedi ? 'COALESCE(three_words_since, NOW())' : 'NULL') . ' WHERE id = ?'
        )->execute([$courseId]);
    }

    /**
     * Quando lo studente ha completato ciascuno dei moduli indicati: l'ultima
     * lezione completata o il primo questionario superato, la data piu'
     * recente delle due. I moduli si passano gia' completati
     * (`Percorso::stati`): qui solo la data.
     *
     * @param int[] $moduleIds
     * @return array<int, string> module_id => data
     */
    public static function completatiIl(int $userId, array $moduleIds): array
    {
        if ($moduleIds === []) {
            return [];
        }

        $in = implode(',', array_map('intval', $moduleIds));
        $date = [];
        $pdo = Database::connection();

        $stmt = $pdo->prepare("SELECT l.module_id, MAX(lp.completed_at) AS il
                                 FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id
                                WHERE lp.user_id = ? AND l.module_id IN ($in) GROUP BY l.module_id");
        $stmt->execute([$userId]);

        foreach ($stmt->fetchAll() as $r) {
            $date[(int) $r['module_id']] = (string) $r['il'];
        }

        $stmt = $pdo->prepare("SELECT q.module_id, MIN(a.attempted_at) AS il
                                 FROM quiz_attempts a JOIN quizzes q ON q.id = a.quiz_id
                                WHERE a.user_id = ? AND a.passed = 1 AND q.module_id IN ($in) GROUP BY q.module_id");
        $stmt->execute([$userId]);

        foreach ($stmt->fetchAll() as $r) {
            $m = (int) $r['module_id'];
            $date[$m] = max($date[$m] ?? '', (string) $r['il']);
        }

        return $date;
    }

    /**
     * Le parole che lo studente ha gia' scritto in un corso: module_id => riga.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function delloStudente(int $userId, int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM module_words WHERE user_id = ? AND course_id = ?'
        );
        $stmt->execute([$userId, $courseId]);
        $righe = [];

        foreach ($stmt->fetchAll() as $r) {
            $righe[(int) $r['module_id']] = $r;
        }

        return $righe;
    }

    /**
     * Salva le tre parole. False se c'erano gia' (la chiave unica): non si
     * cambiano.
     *
     * @param array{0: string, 1: string, 2: string} $parole
     */
    public static function salva(int $userId, int $moduleId, int $courseId, ?int $groupId, array $parole): bool
    {
        $stmt = Database::connection()->prepare(
            'INSERT IGNORE INTO module_words (user_id, module_id, course_id, group_id, word1, word2, word3)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $moduleId, $courseId, $groupId, $parole[0], $parole[1], $parole[2]]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Le parole di un modulo, con chi le ha scritte, per i gruppi indicati.
     *
     * @param int[]|null $groupIds  null: tutti, anche chi non ha un gruppo (l'admin)
     * @return list<array<string, mixed>>
     */
    public static function delModulo(int $moduleId, ?array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $filtro = $groupIds === null ? '' : ' AND w.group_id IN (' . implode(',', array_map('intval', $groupIds)) . ')';
        $stmt = Database::connection()->prepare(
            'SELECT w.id, w.user_id, w.group_id, w.word1, w.word2, w.word3, w.created_at, u.full_name, u.email
               FROM module_words w JOIN users u ON u.id = w.user_id
              WHERE w.module_id = ?' . $filtro . '
              ORDER BY u.full_name, u.email'
        );
        $stmt->execute([$moduleId]);

        return $stmt->fetchAll();
    }

    /**
     * Gli studenti dei gruppi indicati che hanno completato il modulo da
     * quando il corso chiede le parole: quelli a cui le parole sono state
     * chieste. Chi le ha gia' scritte compreso; la vista toglie da qui chi
     * ha risposto, per «non ha ancora risposto».
     *
     * Le stesse regole di `Percorso`: tutte le lezioni del modulo e il suo
     * questionario, se c'e'; un modulo vuoto non si completa.
     *
     * @param int[]|null $groupIds null: tutti gli iscritti del corso
     * @param int|null   $userId   solo questo studente (l'invio controlla lui)
     * @return list<array{user_id: int, full_name: string, completato_il: string}>
     */
    public static function aCuiChiedere(int $moduleId, int $courseId, string $chiesteDal, ?array $groupIds, ?int $userId = null): array
    {
        if ($groupIds === []) {
            return [];
        }

        $pdo = Database::connection();
        $lezioni = (int) $pdo->query('SELECT COUNT(*) FROM lessons WHERE module_id = ' . $moduleId)->fetchColumn();
        $quiz = $pdo->query('SELECT id FROM quizzes WHERE module_id = ' . $moduleId . ' ORDER BY id LIMIT 1')->fetchColumn();

        if ($lezioni === 0 && $quiz === false) {
            return [];
        }

        $gruppo = ($userId === null ? '' : ' AND u.id = ' . $userId)
            . ($groupIds === null ? '' : ' AND EXISTS (SELECT 1 FROM group_members gm WHERE gm.user_id = u.id AND gm.group_id IN ('
            . implode(',', array_map('intval', $groupIds)) . '))');

        $stmt = $pdo->prepare(
            "SELECT u.id AS user_id, u.full_name,
                    (SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id
                      WHERE lp.user_id = u.id AND l.module_id = :m1) AS fatte,
                    (SELECT MAX(lp.completed_at) FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id
                      WHERE lp.user_id = u.id AND l.module_id = :m2) AS ultima,
                    " . ($quiz === false ? 'NULL' : '(SELECT MIN(a.attempted_at) FROM quiz_attempts a WHERE a.user_id = u.id AND a.passed = 1 AND a.quiz_id = ' . (int) $quiz . ')') . " AS superato
               FROM enrollments e JOIN users u ON u.id = e.user_id
              WHERE e.course_id = :c AND u.role = 'studente'" . $gruppo . '
              ORDER BY u.full_name, u.email'
        );
        $stmt->execute(['m1' => $moduleId, 'm2' => $moduleId, 'c' => $courseId]);
        $chi = [];

        foreach ($stmt->fetchAll() as $r) {
            if ((int) $r['fatte'] < $lezioni || ($quiz !== false && $r['superato'] === null)) {
                continue;
            }

            $il = max((string) ($r['ultima'] ?? ''), (string) ($r['superato'] ?? ''));

            if (TreParole::daChiedere($il, $chiesteDal)) {
                $chi[] = ['user_id' => (int) $r['user_id'], 'full_name' => (string) $r['full_name'], 'completato_il' => $il];
            }
        }

        return $chi;
    }
}
