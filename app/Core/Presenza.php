<?php

declare(strict_types=1);

namespace App\Core;

use App\Auth\Auth;

/**
 * L'ultimo accesso alla piattaforma e l'ultima visita a un corso («Studenti
 * fermi», 20/10, Alessandro).
 *
 * Si chiama da `View::render()` per ogni pagina con la cornice: chi e'
 * collegato e' passato di li', e la pagina dice gia' di quale corso e'
 * (`CorsoCorrente::da`). Si scrive solo l'ultima volta, non ogni accesso, e
 * al piu' una volta ogni cinque minuti per persona e per corso: la sessione
 * ricorda quando lo ha scritto, cosi' le altre pagine non toccano il
 * database.
 *
 * `updated_at` degli utenti non cambia: dice quando e' stata modificata la
 * scheda, e una visita non e' una modifica.
 */
final class Presenza
{
    public const OGNI_SECONDI = 300;

    public static function segna(?int $courseId, ?int $adesso = null): void
    {
        if (!Auth::check()) {
            return;
        }

        $userId = (int) Auth::id();
        $adesso ??= time();
        $scritte = $_SESSION['presenza'] ?? [];

        if (self::daScrivere($scritte['piattaforma'] ?? null, $adesso)) {
            Database::connection()->prepare('UPDATE users SET last_seen_at = NOW(), updated_at = updated_at WHERE id = ?')
                ->execute([$userId]);
            $scritte['piattaforma'] = $adesso;
        }

        // Solo l'iscrizione si aggiorna: per lo staff, che non e' iscritto,
        // la riga non c'e' e non succede niente.
        if ($courseId !== null && self::daScrivere($scritte['corso'][$courseId] ?? null, $adesso)) {
            Database::connection()->prepare('UPDATE enrollments SET last_visit_at = NOW() WHERE user_id = ? AND course_id = ?')
                ->execute([$userId, $courseId]);
            $scritte['corso'][$courseId] = $adesso;
        }

        $_SESSION['presenza'] = $scritte;
    }

    /** Se l'ultima scrittura e' di piu' di cinque minuti fa (o non c'e'). */
    public static function daScrivere(?int $ultima, int $adesso): bool
    {
        return $ultima === null || $adesso - $ultima >= self::OGNI_SECONDI;
    }
}
