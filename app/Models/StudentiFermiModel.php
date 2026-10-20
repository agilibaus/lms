<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\StudentiFermi;

/**
 * I dati di «Studenti fermi» (20/10): le iscrizioni non completate degli
 * studenti e, per ciascuna, l'ultima attivita' nel corso. Le regole stanno
 * in `App\Core\StudentiFermi`.
 */
final class StudentiFermiModel
{
    /**
     * Gli studenti fermi da almeno `$soglia` giorni, dal piu' fermo.
     *
     * @param int[]|null $studentIds il perimetro di chi guarda (null = tutti)
     * @return list<array<string, mixed>>
     */
    public static function elenco(int $soglia, ?array $studentIds, ?\DateTimeImmutable $adesso = null): array
    {
        $adesso ??= new \DateTimeImmutable('now');

        if ($studentIds === []) {
            return [];
        }

        $pdo = Database::connection();
        $filtro = $studentIds === null ? '' : ' AND u.id IN (' . implode(',', array_map('intval', $studentIds)) . ')';

        $iscrizioni = $pdo->query(
            "SELECT e.user_id, e.course_id, e.enrolled_at, e.progress_pct, e.last_visit_at,
                    u.full_name, u.email, u.last_seen_at, c.title AS course_title
               FROM enrollments e
               JOIN users u ON u.id = e.user_id
               JOIN courses c ON c.id = e.course_id
              WHERE e.completed_at IS NULL AND u.role = 'studente' AND u.is_active = 1" . $filtro
        )->fetchAll();

        if ($iscrizioni === []) {
            return [];
        }

        $ultime = self::ultimeAttivita($filtro);
        $gruppi = self::gruppi($filtro);
        $righe = [];

        foreach ($iscrizioni as $i) {
            $chiave = (int) $i['user_id'] . '-' . (int) $i['course_id'];
            $ultima = $ultime[$chiave] ?? null;
            $v = StudentiFermi::valuta(
                $ultima['at'] ?? null,
                (string) $i['enrolled_at'],
                $i['last_visit_at'] !== null ? (string) $i['last_visit_at'] : null,
                $soglia,
                $adesso
            );

            if (!$v['fermo']) {
                continue;
            }

            $righe[] = $i + [
                'giorni' => $v['giorni'],
                'stato' => $v['stato'],
                'ultima_at' => $ultima['at'] ?? null,
                'ultima_cosa' => $ultima === null ? null : StudentiFermi::descrivi($ultima['tipo'], (string) $ultima['titolo']),
                'gruppi' => $gruppi[$chiave] ?? [],
                'visita_giorni' => $i['last_visit_at'] === null ? null : StudentiFermi::giorni((string) $i['last_visit_at'], $adesso),
            ];
        }

        usort($righe, static fn (array $a, array $b): int
            => [$b['giorni'], $a['full_name']] <=> [$a['giorni'], $b['full_name']]);

        return $righe;
    }

    /**
     * L'ultima attivita' di ogni studente in ogni corso: chiave
     * «utente-corso». Tutte le attivita' che lasciano una data, in una
     * query sola; la piu' recente si sceglie qui.
     *
     * @return array<string, array{at: string, tipo: string, titolo: string}>
     */
    private static function ultimeAttivita(string $filtro): array
    {
        // Tipo e titolo in una collazione sola: le tabelle non l'hanno tutte
        // uguale, e una UNION fra collazioni diverse non si fa.
        $utenti = $filtro === '' ? '' : ' AND x.user_id IN (SELECT u.id FROM users u WHERE 1' . $filtro . ')';

        $sql = "SELECT x.user_id, x.course_id, x.at, x.tipo, x.titolo FROM (
                    SELECT lp.user_id, m.course_id, lp.completed_at AS at, CONVERT('lezione' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS tipo, CONVERT(l.title USING utf8mb4) COLLATE utf8mb4_unicode_ci AS titolo
                      FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id JOIN modules m ON m.id = l.module_id
                    UNION ALL
                    SELECT v.user_id, m.course_id, v.updated_at, CONVERT('video' USING utf8mb4) COLLATE utf8mb4_unicode_ci, CONVERT(l.title USING utf8mb4) COLLATE utf8mb4_unicode_ci
                      FROM lesson_video_progress v JOIN lessons l ON l.id = v.lesson_id JOIN modules m ON m.id = l.module_id
                    UNION ALL
                    SELECT a.user_id, m.course_id, a.attempted_at, CONVERT('questionario' USING utf8mb4) COLLATE utf8mb4_unicode_ci, CONVERT(q.title USING utf8mb4) COLLATE utf8mb4_unicode_ci
                      FROM quiz_attempts a JOIN quizzes q ON q.id = a.quiz_id JOIN modules m ON m.id = q.module_id
                    UNION ALL
                    SELECT la.user_id, m.course_id, la.joined_at, CONVERT('incontro' USING utf8mb4) COLLATE utf8mb4_unicode_ci, CONVERT(s.title USING utf8mb4) COLLATE utf8mb4_unicode_ci
                      FROM live_session_attendance la JOIN live_sessions s ON s.id = la.session_id JOIN modules m ON m.id = s.module_id
                    UNION ALL
                    SELECT cq.student_id, cq.course_id, cq.created_at, CONVERT('domanda' USING utf8mb4) COLLATE utf8mb4_unicode_ci, CONVERT('' USING utf8mb4) COLLATE utf8mb4_unicode_ci
                      FROM course_questions cq
                    UNION ALL
                    SELECT r.author_id, r.course_id, r.created_at, CONVERT('risorsa' USING utf8mb4) COLLATE utf8mb4_unicode_ci, CONVERT(r.title USING utf8mb4) COLLATE utf8mb4_unicode_ci
                      FROM board_resources r
                    UNION ALL
                    SELECT bu.user_id, r.course_id, bu.created_at, CONVERT('racconto' USING utf8mb4) COLLATE utf8mb4_unicode_ci, CONVERT(r.title USING utf8mb4) COLLATE utf8mb4_unicode_ci
                      FROM board_uses bu JOIN board_resources r ON r.id = bu.resource_id
                ) x WHERE x.at IS NOT NULL" . $utenti;

        $ultime = [];

        foreach (Database::connection()->query($sql) as $r) {
            $chiave = (int) $r['user_id'] . '-' . (int) $r['course_id'];

            if (!isset($ultime[$chiave]) || (string) $r['at'] > $ultime[$chiave]['at']) {
                $ultime[$chiave] = ['at' => (string) $r['at'], 'tipo' => (string) $r['tipo'], 'titolo' => (string) $r['titolo']];
            }
        }

        return $ultime;
    }

    /**
     * I gruppi di ogni studente a cui il corso e' assegnato: chiave
     * «utente-corso».
     *
     * @return array<string, list<string>>
     */
    private static function gruppi(string $filtro): array
    {
        $sql = 'SELECT gm.user_id, gca.course_id, g.name
                  FROM group_members gm
                  JOIN group_course_access gca ON gca.group_id = gm.group_id
                  JOIN `groups` g ON g.id = gm.group_id
                  JOIN users u ON u.id = gm.user_id
                 WHERE 1' . $filtro . ' ORDER BY g.name';
        $gruppi = [];

        foreach (Database::connection()->query($sql) as $r) {
            $gruppi[(int) $r['user_id'] . '-' . (int) $r['course_id']][] = (string) $r['name'];
        }

        return $gruppi;
    }
}
