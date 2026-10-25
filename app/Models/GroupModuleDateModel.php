<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Le date dei moduli per gruppo (26/10, Alessandro, conflitto 3): tabella
 * `group_module_dates`. Una riga = il gruppo ha la sua data per quel
 * modulo; nessuna riga = segue la data del corso. Quale data vale per lo
 * studente lo dice `ModuleModel::dataPer`.
 */
final class GroupModuleDateModel
{
    /**
     * Le date del gruppo per i moduli di un corso.
     *
     * @return array<int, string> chiave: id del modulo
     */
    public static function forGroupCourse(int $groupId, int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT d.module_id, d.available_from
             FROM group_module_dates d
             INNER JOIN modules m ON m.id = d.module_id
             WHERE d.group_id = :g AND m.course_id = :c'
        );
        $stmt->execute(['g' => $groupId, 'c' => $courseId]);
        $out = [];

        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['module_id']] = (string) $r['available_from'];
        }

        return $out;
    }

    /**
     * Salva le date del gruppo per i moduli del corso: quelle date si
     * scrivono, quelle vuote (null) si tolgono, e il gruppo torna a seguire
     * il corso. Un id che non e' un modulo del corso si ignora.
     *
     * @param array<int, ?string> $date chiave: id del modulo
     */
    public static function save(int $groupId, int $courseId, array $date): void
    {
        $db = Database::connection();
        $moduli = array_map(static fn (array $m): int => (int) $m['id'], ModuleModel::forCourse($courseId));
        $scrivi = $db->prepare(
            'INSERT INTO group_module_dates (group_id, module_id, available_from) VALUES (:g, :m, :d)
             ON DUPLICATE KEY UPDATE available_from = VALUES(available_from)'
        );
        $togli = $db->prepare('DELETE FROM group_module_dates WHERE group_id = :g AND module_id = :m');

        $db->beginTransaction();

        foreach ($moduli as $moduleId) {
            $data = $date[$moduleId] ?? null;

            if ($data === null) {
                $togli->execute(['g' => $groupId, 'm' => $moduleId]);
            } else {
                $scrivi->execute(['g' => $groupId, 'm' => $moduleId, 'd' => $data]);
            }
        }

        $db->commit();
    }
}
