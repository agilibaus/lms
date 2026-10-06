<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;

/**
 * Chi vede la pagina di un gruppo, e chi vede la foto del profilo di chi.
 *
 * Le due domande stanno nella stessa classe perche' sono la stessa regola
 * guardata da due lati: la pagina del gruppo mostra le foto dei suoi
 * partecipanti, e una foto si vede solo da chi quella pagina la puo' aprire.
 * Scritte in due posti direbbero presto due cose diverse.
 *
 * LA REGOLA, decisa da Elena il 06/10:
 *   - l'amministratore vede tutto;
 *   - i partecipanti di un gruppo vedono la pagina del gruppo e le foto dei
 *     compagni;
 *   - il tutor del gruppo e' parte del gruppo anche se non ne e' membro:
 *     vede la pagina e le foto, e la sua foto sta al centro del cerchio,
 *     quindi la vedono i partecipanti;
 *   - ognuno vede la propria foto, anche senza gruppi: e' quella della barra
 *     laterale e del profilo.
 *
 * Chi **non** c'e': l'assistente. Affianca il tutor nei report, ma Elena non
 * l'ha incluso fra chi vede le foto, e nessuna pagina dello staff le mostra.
 * Aggiungerlo e' una decisione, non una correzione.
 *
 * La condizione «stare nello stesso gruppo» e' scritta una volta sola, in
 * SQL: un utente fa parte di un gruppo se ne e' membro **oppure** se ne e' il
 * tutor.
 */
final class GroupPeers
{
    /**
     * Gruppi di cui un utente fa parte, come membro o come tutor. E' il
     * frammento che entrambe le domande riusano.
     */
    private const PARTE_DI = "SELECT gm.group_id FROM group_members gm WHERE gm.user_id = %s
                              UNION
                              SELECT g.id FROM `groups` g WHERE g.tutor_id = %s";

    public static function canViewGroup(int $viewerId, int $groupId): bool
    {
        if (Auth::hasRole('admin')) {
            return true;
        }

        $sql = 'SELECT 1 FROM (' . sprintf(self::PARTE_DI, ':v1', ':v2') . ') parte
                WHERE parte.group_id = :g LIMIT 1';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['v1' => $viewerId, 'v2' => $viewerId, 'g' => $groupId]);

        return $stmt->fetchColumn() !== false;
    }

    public static function canSeeAvatar(int $viewerId, int $ownerId): bool
    {
        if ($viewerId === $ownerId || Auth::hasRole('admin')) {
            return true;
        }

        $sql = 'SELECT 1
                FROM (' . sprintf(self::PARTE_DI, ':v1', ':v2') . ') di_chi_guarda
                INNER JOIN (' . sprintf(self::PARTE_DI, ':o1', ':o2') . ') di_chi_e
                    ON di_chi_e.group_id = di_chi_guarda.group_id
                LIMIT 1';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['v1' => $viewerId, 'v2' => $viewerId, 'o1' => $ownerId, 'o2' => $ownerId]);

        return $stmt->fetchColumn() !== false;
    }
}
