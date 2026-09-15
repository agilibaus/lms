<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per la tabella `certificates`.
 * Un certificato revocato resta in tabella (revoked_at valorizzato) cosi'
 * l'emissione automatica non lo rigenera al primo accesso successivo.
 */
class CertificateModel
{
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM certificates WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $certificate = $stmt->fetch();

        return $certificate ?: null;
    }

    public static function findForUserAndCourse(int $userId, int $courseId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM certificates WHERE user_id = :user_id AND course_id = :course_id LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'course_id' => $courseId]);
        $certificate = $stmt->fetch();

        return $certificate ?: null;
    }

    /**
     * Ricerca per codice pubblico di verifica (pagina accessibile senza login).
     */
    public static function findByCode(string $code): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.*, u.full_name, co.title AS course_title
             FROM certificates c
             INNER JOIN users u ON u.id = c.user_id
             INNER JOIN courses co ON co.id = c.course_id
             WHERE c.certificate_code = :code LIMIT 1'
        );
        $stmt->execute(['code' => $code]);
        $certificate = $stmt->fetch();

        return $certificate ?: null;
    }

    /**
     * Certificati di un utente (i propri, per la pagina "Certificati").
     */
    public static function forUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.*, co.title AS course_title
             FROM certificates c
             INNER JOIN courses co ON co.id = c.course_id
             WHERE c.user_id = :user_id
             ORDER BY c.issued_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * Tutti i certificati emessi (vista staff).
     */
    public static function all(): array
    {
        return Database::connection()->query(
            'SELECT c.*, u.full_name, u.email, co.title AS course_title
             FROM certificates c
             INNER JOIN users u ON u.id = c.user_id
             INNER JOIN courses co ON co.id = c.course_id
             ORDER BY c.issued_at DESC'
        )->fetchAll();
    }

    public static function create(int $userId, int $courseId, string $code, string $filePath, ?int $issuedBy): int
    {
        $db = Database::connection();

        $stmt = $db->prepare(
            'INSERT INTO certificates (user_id, course_id, certificate_code, file_path, issued_by)
             VALUES (:user_id, :course_id, :certificate_code, :file_path, :issued_by)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'course_id' => $courseId,
            'certificate_code' => $code,
            'file_path' => $filePath,
            'issued_by' => $issuedBy,
        ]);

        return (int) $db->lastInsertId();
    }

    /**
     * Riattiva un certificato revocato riemettendolo (nuovo file, stessa riga/codice).
     */
    public static function reinstate(int $id, string $filePath, ?int $issuedBy): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE certificates
             SET file_path = :file_path, issued_by = :issued_by, issued_at = NOW(),
                 revoked_at = NULL, revoked_reason = NULL
             WHERE id = :id'
        );
        $stmt->execute(['file_path' => $filePath, 'issued_by' => $issuedBy, 'id' => $id]);
    }

    public static function revoke(int $id, ?string $reason): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE certificates SET revoked_at = NOW(), revoked_reason = :reason WHERE id = :id'
        );
        $stmt->execute(['reason' => $reason, 'id' => $id]);
    }

    /**
     * Genera un codice di verifica univoco e leggibile (es. PST-4F2A-91BC).
     */
    public static function generateUniqueCode(): string
    {
        do {
            $code = sprintf(
                'PST-%s-%s',
                strtoupper(bin2hex(random_bytes(2))),
                strtoupper(bin2hex(random_bytes(2)))
            );
        } while (self::findByCode($code) !== null);

        return $code;
    }
}
