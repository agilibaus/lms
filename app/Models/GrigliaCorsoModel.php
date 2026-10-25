<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\GrigliaCorso;

/**
 * I dati della «Griglia del corso» (21/10): i moduli con le loro attivita',
 * nell'ordine in cui le incontra lo studente, e per ogni iscritto lo stato di
 * ciascuna. Le regole stanno in `App\Core\GrigliaCorso`.
 *
 * Poche query, tutte per corso: le attivita', gli iscritti, e poi i fatti di
 * tutti gli iscritti in una volta per tipo. Una query per casella, con 19
 * attivita' e 30 iscritti, sarebbero 570.
 */
final class GrigliaCorsoModel
{
    /**
     * I moduli del corso, ciascuno con le sue attivita': le lezioni nel loro
     * ordine, poi gli incontri per data, poi il questionario — come nella
     * pagina del corso, dove il questionario chiude il modulo.
     *
     * @return list<array{id: int, titolo: string, voci: list<array{chiave: string, tipo: string, id: int, titolo: string, futuro: bool, gruppo_id: ?int}>}>
     */
    public static function moduli(int $courseId, ?\DateTimeImmutable $adesso = null): array
    {
        $adesso ??= new \DateTimeImmutable('now');
        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT id, title FROM modules WHERE course_id = ? ORDER BY position, id');
        $stmt->execute([$courseId]);
        $moduli = [];

        foreach ($stmt->fetchAll() as $m) {
            $moduli[(int) $m['id']] = ['id' => (int) $m['id'], 'titolo' => (string) $m['title'], 'voci' => []];
        }

        if ($moduli === []) {
            return [];
        }

        $in = implode(',', array_keys($moduli));
        $voce = static fn (string $tipo, array $r, bool $futuro = false, ?int $gruppo = null): array => [
            'chiave' => $tipo[0] . (int) $r['id'],
            'tipo' => $tipo,
            'id' => (int) $r['id'],
            'titolo' => (string) $r['title'],
            'futuro' => $futuro,
            'gruppo_id' => $gruppo,
        ];

        foreach ($pdo->query("SELECT id, module_id, title FROM lessons WHERE module_id IN ($in) ORDER BY position, id") as $r) {
            $moduli[(int) $r['module_id']]['voci'][] = $voce(GrigliaCorso::LEZIONE, $r);
        }

        // Un incontro e' futuro finche' non e' finito: durante l'incontro
        // chi non e' ancora entrato non e' «assente».
        $ora = $adesso->format('Y-m-d H:i:s');

        foreach ($pdo->query("SELECT id, module_id, group_id, title, ends_at FROM live_sessions WHERE module_id IN ($in) ORDER BY starts_at, id") as $r) {
            $moduli[(int) $r['module_id']]['voci'][] = $voce(
                GrigliaCorso::INCONTRO,
                $r,
                (string) $r['ends_at'] > $ora,
                $r['group_id'] === null ? null : (int) $r['group_id']
            );
        }

        foreach ($pdo->query("SELECT id, module_id, title FROM quizzes WHERE module_id IN ($in) ORDER BY id") as $r) {
            $moduli[(int) $r['module_id']]['voci'][] = $voce(GrigliaCorso::QUESTIONARIO, $r);
        }

        return array_values($moduli);
    }

    /**
     * I gruppi a cui il corso e' assegnato: id => nome.
     *
     * @return array<int, string>
     */
    public static function gruppi(int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT g.id, g.name FROM group_course_access a JOIN `groups` g ON g.id = a.group_id
              WHERE a.course_id = ? ORDER BY g.name, g.id'
        );
        $stmt->execute([$courseId]);
        $gruppi = [];

        foreach ($stmt->fetchAll() as $g) {
            $gruppi[(int) $g['id']] = (string) $g['name'];
        }

        return $gruppi;
    }

    /**
     * Gli iscritti, compresi quelli che hanno completato il corso (21/10:
     * servono come confronto), con lo stato di ogni attivita'.
     *
     * @param list<array{voci: list<array>}> $moduli    da `moduli()`
     * @param int[]|null                      $studentIds il perimetro di chi guarda (null = tutti)
     * @param int|null                        $gruppoId   solo i membri di questo gruppo
     * @return list<array<string, mixed>>
     */
    public static function studenti(int $courseId, array $moduli, ?array $studentIds, ?int $gruppoId): array
    {
        if ($studentIds === []) {
            return [];
        }

        $pdo = Database::connection();
        $filtro = $studentIds === null ? '' : ' AND u.id IN (' . implode(',', array_map('intval', $studentIds)) . ')';

        if ($gruppoId !== null) {
            $filtro .= ' AND EXISTS (SELECT 1 FROM group_members gm WHERE gm.user_id = u.id AND gm.group_id = ' . $gruppoId . ')';
        }

        $stmt = $pdo->prepare(
            "SELECT u.id AS user_id, u.full_name, u.email, e.progress_pct, e.completed_at,
                    (cert.id IS NOT NULL AND cert.revoked_at IS NULL) AS certificato
               FROM enrollments e
               JOIN users u ON u.id = e.user_id
               LEFT JOIN certificates cert ON cert.user_id = u.id AND cert.course_id = e.course_id
              WHERE e.course_id = ? AND u.role = 'studente'" . $filtro . '
              ORDER BY u.full_name, u.email'
        );
        $stmt->execute([$courseId]);
        $iscritti = $stmt->fetchAll();

        if ($iscritti === []) {
            return [];
        }

        $utenti = implode(',', array_map(static fn (array $r): int => (int) $r['user_id'], $iscritti));
        $voci = array_merge(...array_map(static fn (array $m): array => $m['voci'], $moduli));
        $ids = static function (string $tipo) use ($voci): string {
            $scelti = array_map(static fn (array $v): int => $v['id'], array_filter($voci, static fn (array $v): bool => $v['tipo'] === $tipo));

            return $scelti === [] ? '0' : implode(',', $scelti);
        };

        $lezioni = [];

        foreach ($pdo->query("SELECT user_id, lesson_id, completed_at FROM lesson_progress
                               WHERE user_id IN ($utenti) AND lesson_id IN ({$ids(GrigliaCorso::LEZIONE)})") as $r) {
            $lezioni[(int) $r['user_id']][(int) $r['lesson_id']] = (string) $r['completed_at'];
        }

        $presenze = [];

        foreach ($pdo->query("SELECT user_id, session_id, joined_at FROM live_session_attendance
                               WHERE user_id IN ($utenti) AND session_id IN ({$ids(GrigliaCorso::INCONTRO)})") as $r) {
            $presenze[(int) $r['user_id']][(int) $r['session_id']] = $r['joined_at'] === null ? '' : (string) $r['joined_at'];
        }

        $tentativi = [];

        foreach ($pdo->query("SELECT user_id, quiz_id, MAX(score_pct) AS migliore, MAX(passed) AS superato,
                                     COUNT(*) AS quanti, MAX(attempted_at) AS ultimo
                                FROM quiz_attempts
                               WHERE user_id IN ($utenti) AND quiz_id IN ({$ids(GrigliaCorso::QUESTIONARIO)})
                               GROUP BY user_id, quiz_id") as $r) {
            $tentativi[(int) $r['user_id']][(int) $r['quiz_id']] = $r;
        }

        $membri = [];

        foreach ($pdo->query("SELECT gm.user_id, g.id, g.name FROM group_members gm
                                JOIN group_course_access a ON a.group_id = gm.group_id AND a.course_id = " . $courseId . "
                                JOIN `groups` g ON g.id = gm.group_id
                               WHERE gm.user_id IN ($utenti) ORDER BY g.name") as $r) {
            $membri[(int) $r['user_id']][(int) $r['id']] = (string) $r['name'];
        }

        // Chi e' atteso a ciascun incontro: la regola degli incontri, una sola
        // (`LiveSessionModel::atteso`, 25/10). Agli altri l'incontro e' «non
        // previsto»: un altro gruppo, o una classe di un altro periodo.
        $attesi = LiveSessionModel::attesi(array_values(array_unique(array_map(
            static fn (array $v): int => $v['id'],
            array_filter($voci, static fn (array $v): bool => $v['tipo'] === GrigliaCorso::INCONTRO)
        ))));

        $righe = [];

        foreach ($iscritti as $i) {
            $u = (int) $i['user_id'];
            $caselle = [];

            foreach ($voci as $v) {
                $caselle[$v['chiave']] = match ($v['tipo']) {
                    GrigliaCorso::LEZIONE => isset($lezioni[$u][$v['id']])
                        ? ['stato' => GrigliaCorso::FATTO, 'quando' => $lezioni[$u][$v['id']]]
                        : ['stato' => GrigliaCorso::VUOTO],
                    GrigliaCorso::INCONTRO => [
                        'stato' => GrigliaCorso::statoIncontro(
                            isset($presenze[$u][$v['id']]),
                            $v['futuro'],
                            in_array($u, $attesi[$v['id']] ?? [], true)
                        ),
                        'quando' => ($presenze[$u][$v['id']] ?? '') ?: null,
                    ],
                    default => isset($tentativi[$u][$v['id']])
                        ? [
                            'stato' => GrigliaCorso::statoQuestionario((int) $tentativi[$u][$v['id']]['superato'] === 1),
                            'punteggio' => (float) $tentativi[$u][$v['id']]['migliore'],
                            'tentativi' => (int) $tentativi[$u][$v['id']]['quanti'],
                            'quando' => (string) $tentativi[$u][$v['id']]['ultimo'],
                        ]
                        : ['stato' => GrigliaCorso::VUOTO],
                };
            }

            $righe[] = [
                'user_id' => $u,
                'full_name' => (string) $i['full_name'],
                'email' => (string) $i['email'],
                'progress_pct' => (float) $i['progress_pct'],
                'completed_at' => $i['completed_at'],
                'certificato' => (bool) $i['certificato'],
                'gruppi' => array_values($membri[$u] ?? []),
                'caselle' => $caselle,
            ];
        }

        return $righe;
    }
}
