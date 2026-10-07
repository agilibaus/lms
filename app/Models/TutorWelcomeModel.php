<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * I benvenuti dei tutor (`course_tutor_welcomes`) e le visite degli studenti
 * (`course_welcome_views`). La regola completo/ridotto sta in
 * `App\Core\TutorWelcome`.
 */
final class TutorWelcomeModel
{
    /**
     * I tutor che hanno studenti in questo corso, cioe' i tutor dei gruppi a
     * cui il corso e' assegnato, ciascuno con il suo benvenuto se c'e'. E' la
     * lista della pagina di modifica del corso: un benvenuto si carica per un
     * tutor che il corso lo segue davvero.
     *
     * @return list<array{tutor_id:int, tutor_name:string, contact_email:?string, groups:string, welcome_id:?int,
     *                    photo_path:?string, audio_path:?string, transcript:?string, updated_at:?string}>
     */
    public static function tutorsForCourse(int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT t.id AS tutor_id, t.full_name AS tutor_name, t.contact_email,
                    GROUP_CONCAT(DISTINCT g.name ORDER BY g.name SEPARATOR ', ') AS `groups`,
                    w.id AS welcome_id, w.photo_path, w.audio_path, w.transcript, w.updated_at
             FROM group_course_access gca
             INNER JOIN `groups` g ON g.id = gca.group_id
             INNER JOIN users t ON t.id = g.tutor_id
             LEFT JOIN course_tutor_welcomes w ON w.course_id = gca.course_id AND w.tutor_id = t.id
             WHERE gca.course_id = :course
             GROUP BY t.id, t.full_name, t.contact_email, w.id, w.photo_path, w.audio_path, w.transcript, w.updated_at
             ORDER BY t.full_name, t.email"
        );
        $stmt->execute(['course' => $courseId]);

        return $stmt->fetchAll();
    }

    /**
     * Il benvenuto che uno studente sente in un corso: quello del tutor di un
     * gruppo di cui fa parte e a cui il corso e' assegnato. Nessuno, se lo
     * studente non sta in un gruppo con un tutor (iscritto dal catalogo:
     * decisione di Elena) o se il suo tutor non ne ha caricato uno.
     *
     * Uno studente in due gruppi dello stesso corso, con due tutor che hanno
     * entrambi un benvenuto, sente quello del tutor che viene prima per nome:
     * un caso raro, e un ordine fisso invece di uno che cambia fra le visite.
     *
     * @return array{id:int, course_id:int, tutor_id:int, tutor_name:string, contact_email:?string,
     *               photo_path:string, audio_path:string, transcript:string,
     *               group_name:string, whatsapp_url:?string}|null
     */
    public static function forStudent(int $courseId, int $userId): ?array
    {
        // Il gruppo e' quello attraverso cui lo studente ha il tutor in questo
        // corso: da li' vengono il nome e il link WhatsApp (07/10). Con due
        // gruppi dello stesso tutor nello stesso corso, il primo per nome.
        $stmt = Database::connection()->prepare(
            'SELECT w.id, w.course_id, w.tutor_id, t.full_name AS tutor_name, t.contact_email,
                    w.photo_path, w.audio_path, w.transcript,
                    g.name AS group_name, g.whatsapp_url
             FROM course_tutor_welcomes w
             INNER JOIN users t ON t.id = w.tutor_id
             INNER JOIN group_members gm ON gm.user_id = :user
             INNER JOIN `groups` g ON g.id = gm.group_id AND g.tutor_id = w.tutor_id
             INNER JOIN group_course_access gca ON gca.group_id = g.id AND gca.course_id = w.course_id
             WHERE w.course_id = :course
             ORDER BY t.full_name, t.email, g.name, g.id
             LIMIT 1'
        );
        $stmt->execute(['course' => $courseId, 'user' => $userId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT w.*, t.full_name AS tutor_name
             FROM course_tutor_welcomes w INNER JOIN users t ON t.id = w.tutor_id
             WHERE w.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function findFor(int $courseId, int $tutorId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM course_tutor_welcomes WHERE course_id = :c AND tutor_id = :t'
        );
        $stmt->execute(['c' => $courseId, 't' => $tutorId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function save(int $courseId, int $tutorId, string $photo, string $audio, string $transcript): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO course_tutor_welcomes (course_id, tutor_id, photo_path, audio_path, transcript)
             VALUES (:c, :t, :p, :a, :x)
             ON DUPLICATE KEY UPDATE photo_path = VALUES(photo_path), audio_path = VALUES(audio_path),
                                     transcript = VALUES(transcript)'
        );
        $stmt->execute(['c' => $courseId, 't' => $tutorId, 'p' => $photo, 'a' => $audio, 'x' => $transcript]);
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM course_tutor_welcomes WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * Registra una visita alla pagina del corso e restituisce lo stato
     * aggiornato: quante visite, compresa questa, e se l'audio e' stato
     * ascoltato. Una scrittura sola, e il conto lo fa il database.
     *
     * @return array{visits:int, listened:bool}
     */
    public static function recordVisit(int $userId, int $courseId): array
    {
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO course_welcome_views (user_id, course_id, visits) VALUES (:u, :c, 1)
             ON DUPLICATE KEY UPDATE visits = visits + 1'
        )->execute(['u' => $userId, 'c' => $courseId]);

        $stmt = $db->prepare('SELECT visits, listened_at FROM course_welcome_views WHERE user_id = :u AND course_id = :c');
        $stmt->execute(['u' => $userId, 'c' => $courseId]);
        $row = $stmt->fetch();

        return ['visits' => (int) $row['visits'], 'listened' => $row['listened_at'] !== null];
    }

    public static function markListened(int $userId, int $courseId): void
    {
        Database::connection()->prepare(
            'INSERT INTO course_welcome_views (user_id, course_id, visits, listened_at) VALUES (:u, :c, 0, NOW())
             ON DUPLICATE KEY UPDATE listened_at = COALESCE(listened_at, NOW())'
        )->execute(['u' => $userId, 'c' => $courseId]);
    }
}
