<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Bacheca;
use App\Core\Database;

/**
 * I dati della Bacheca (17/10): categorie, risorse, «Questa me la tengo» e
 * «Come l'ho utilizzata». Tabelle `board_*`. Le regole stanno in
 * `App\Core\Bacheca`, chi puo' che cosa in `App\Auth\BachecaScope`.
 */
final class BoardModel
{
    /** Le colonne dell'autore, per `PersonName::shown` e l'attribuzione. */
    private const AUTORE = 'u.first_name, u.last_name, u.full_name, u.name_display, u.role AS author_role';

    // -----------------------------------------------------------------
    // Categorie
    // -----------------------------------------------------------------

    /**
     * Le categorie di un corso, in ordine, con il numero delle risorse
     * pubblicate (`pubblicate`) e di tutte (`tutte`, per sapere se si puo'
     * eliminare).
     *
     * @return list<array<string, mixed>>
     */
    public static function categorie(int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM board_resources r WHERE r.category_id = c.id AND r.status = 'published') AS pubblicate,
                    (SELECT COUNT(*) FROM board_resources r WHERE r.category_id = c.id) AS tutte
             FROM board_categories c
             WHERE c.course_id = :c
             ORDER BY c.position, c.id"
        );
        $stmt->execute(['c' => $courseId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function categoria(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM board_categories WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public static function creaCategoria(int $courseId, string $nome, string $colore, ?string $descrizione): int
    {
        $db = Database::connection();
        $pos = $db->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM board_categories WHERE course_id = :c');
        $pos->execute(['c' => $courseId]);

        $db->prepare('INSERT INTO board_categories (course_id, name, color, description, position) VALUES (:c, :n, :k, :d, :p)')
            ->execute(['c' => $courseId, 'n' => $nome, 'k' => $colore, 'd' => $descrizione, 'p' => (int) $pos->fetchColumn()]);

        return (int) $db->lastInsertId();
    }

    public static function aggiornaCategoria(int $id, string $nome, string $colore, ?string $descrizione): void
    {
        Database::connection()->prepare('UPDATE board_categories SET name = :n, color = :k, description = :d WHERE id = :id')
            ->execute(['id' => $id, 'n' => $nome, 'k' => $colore, 'd' => $descrizione]);
    }

    /** Solo se e' vuota: una categoria con delle risorse resta. */
    public static function eliminaCategoria(int $id): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM board_categories WHERE id = :id AND NOT EXISTS (SELECT 1 FROM board_resources WHERE category_id = :id2)'
        );
        $stmt->execute(['id' => $id, 'id2' => $id]);

        return $stmt->rowCount() === 1;
    }

    /** Scambia la categoria con quella sopra o sotto. */
    public static function spostaCategoria(int $id, string $verso): void
    {
        $cat = self::categoria($id);

        if ($cat === null) {
            return;
        }

        $elenco = self::categorie((int) $cat['course_id']);
        $ids = array_map(static fn (array $c): int => (int) $c['id'], $elenco);
        $i = array_search($id, $ids, true);
        $j = $verso === 'up' ? $i - 1 : $i + 1;

        if ($i === false || !isset($ids[$j])) {
            return;
        }

        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        $upd = Database::connection()->prepare('UPDATE board_categories SET position = :p WHERE id = :id');

        foreach ($ids as $p => $catId) {
            $upd->execute(['p' => $p, 'id' => $catId]);
        }
    }

    // -----------------------------------------------------------------
    // Risorse
    // -----------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public static function risorsa(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.*, c.name AS category_name, c.color AS category_color, ' . self::AUTORE . '
             FROM board_resources r
             INNER JOIN board_categories c ON c.id = r.category_id
             LEFT JOIN users u ON u.id = r.author_id
             WHERE r.id = :id'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Le pubblicate di una categoria, o di un corso intero, in ordine.
     *
     * @return list<array<string, mixed>>
     */
    public static function pubblicate(int $courseId, ?int $categoryId = null): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT r.*, c.name AS category_name, c.color AS category_color, " . self::AUTORE . "
             FROM board_resources r
             INNER JOIN board_categories c ON c.id = r.category_id
             LEFT JOIN users u ON u.id = r.author_id
             WHERE r.course_id = :c AND r.status = 'published'" . ($categoryId === null ? '' : ' AND r.category_id = :k')
        );
        $stmt->execute(['c' => $courseId] + ($categoryId === null ? [] : ['k' => $categoryId]));

        return Bacheca::ordina($stmt->fetchAll());
    }

    /**
     * Le proposte in attesa di un corso, dalla piu' vecchia; con `$autori`
     * solo quelle di questi studenti (null: tutte).
     *
     * @param list<int>|null $autori
     * @return list<array<string, mixed>>
     */
    public static function inAttesa(int $courseId, ?array $autori): array
    {
        if ($autori === []) {
            return [];
        }

        $filtro = $autori === null ? '' : ' AND r.author_id IN (' . implode(',', array_map('intval', $autori)) . ')';
        $stmt = Database::connection()->prepare(
            "SELECT r.*, c.name AS category_name, c.color AS category_color, " . self::AUTORE . "
             FROM board_resources r
             INNER JOIN board_categories c ON c.id = r.category_id
             LEFT JOIN users u ON u.id = r.author_id
             WHERE r.course_id = :c AND r.status = 'pending'" . $filtro . '
             ORDER BY r.created_at, r.id'
        );
        $stmt->execute(['c' => $courseId]);

        return $stmt->fetchAll();
    }

    /**
     * Le proposte di uno studente in un corso, dalla piu' recente, con lo
     * stato: «Le tue proposte».
     *
     * @return list<array<string, mixed>>
     */
    public static function proposteDi(int $userId, int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, title, status, created_at FROM board_resources
             WHERE author_id = :u AND course_id = :c ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute(['u' => $userId, 'c' => $courseId]);

        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $dati da `Bacheca::valida()`
     */
    public static function crea(int $courseId, int $authorId, array $dati, string $stato): int
    {
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO board_resources (course_id, category_id, author_id, title, kind, url, description, status, decided_by, published_at)
             VALUES (:c, :k, :a, :t, :ki, :u, :d, :s, :by, :at)'
        )->execute([
            'c' => $courseId, 'k' => $dati['category_id'], 'a' => $authorId, 't' => $dati['title'], 'ki' => $dati['kind'],
            'u' => $dati['url'], 'd' => $dati['description'], 's' => $stato,
            'by' => $stato === Bacheca::PUBLISHED ? $authorId : null,
            'at' => $stato === Bacheca::PUBLISHED ? date('Y-m-d H:i:s') : null,
        ]);

        return (int) $db->lastInsertId();
    }

    /** @param array<string, mixed> $dati da `Bacheca::valida()` */
    public static function aggiorna(int $id, array $dati): void
    {
        Database::connection()->prepare(
            'UPDATE board_resources SET category_id = :k, title = :t, kind = :ki, url = :u, description = :d WHERE id = :id'
        )->execute(['id' => $id, 'k' => $dati['category_id'], 't' => $dati['title'], 'ki' => $dati['kind'],
            'u' => $dati['url'], 'd' => $dati['description']]);
    }

    public static function impostaFile(int $id, ?string $path, ?string $nome, ?int $byte): void
    {
        Database::connection()->prepare('UPDATE board_resources SET file_path = :p, file_name = :n, file_size = :b WHERE id = :id')
            ->execute(['id' => $id, 'p' => $path, 'n' => $nome, 'b' => $byte]);
    }

    /** Pubblica una proposta in attesa, nella categoria scelta da chi la pubblica. */
    public static function pubblica(int $id, int $categoryId, int $chi): bool
    {
        $stmt = Database::connection()->prepare(
            "UPDATE board_resources SET status = 'published', category_id = :k, decided_by = :by, published_at = NOW()
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute(['id' => $id, 'k' => $categoryId, 'by' => $chi]);

        return $stmt->rowCount() === 1;
    }

    /** «Non pubblicare» una proposta in attesa. */
    public static function scarta(int $id, int $chi): bool
    {
        $stmt = Database::connection()->prepare(
            "UPDATE board_resources SET status = 'discarded', decided_by = :by WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute(['id' => $id, 'by' => $chi]);

        return $stmt->rowCount() === 1;
    }

    /** Toglie una pubblicata dalla bacheca: non si cancella, torna «Non pubblicata». */
    public static function togli(int $id, int $chi): bool
    {
        $stmt = Database::connection()->prepare(
            "UPDATE board_resources SET status = 'discarded', featured = 0, decided_by = :by WHERE id = :id AND status = 'published'"
        );
        $stmt->execute(['id' => $id, 'by' => $chi]);

        return $stmt->rowCount() === 1;
    }

    public static function evidenza(int $id, bool $si): void
    {
        Database::connection()->prepare("UPDATE board_resources SET featured = :f WHERE id = :id AND status = 'published'")
            ->execute(['id' => $id, 'f' => $si ? 1 : 0]);
    }

    // -----------------------------------------------------------------
    // «Questa me la tengo»
    // -----------------------------------------------------------------

    /** Mette o toglie; risponde se adesso la tiene. */
    public static function tieni(int $userId, int $resourceId): bool
    {
        $db = Database::connection();
        $del = $db->prepare('DELETE FROM board_saves WHERE user_id = :u AND resource_id = :r');
        $del->execute(['u' => $userId, 'r' => $resourceId]);

        if ($del->rowCount() > 0) {
            return false;
        }

        $db->prepare('INSERT IGNORE INTO board_saves (user_id, resource_id) VALUES (:u, :r)')
            ->execute(['u' => $userId, 'r' => $resourceId]);

        return true;
    }

    /**
     * Le risorse pubblicate di un corso che questa persona si tiene.
     *
     * @return list<int>
     */
    public static function tenute(int $userId, int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT s.resource_id FROM board_saves s
             INNER JOIN board_resources r ON r.id = s.resource_id AND r.course_id = :c AND r.status = 'published'
             WHERE s.user_id = :u"
        );
        $stmt->execute(['u' => $userId, 'c' => $courseId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    // -----------------------------------------------------------------
    // «Come l'ho utilizzata»
    // -----------------------------------------------------------------

    /**
     * I racconti di queste risorse, dal piu' vecchio, raggruppati per
     * risorsa. Anche i nascosti: decide la vista chi li vede.
     *
     * @param list<int> $resourceIds
     * @return array<int, list<array<string, mixed>>>
     */
    public static function usi(array $resourceIds): array
    {
        if ($resourceIds === []) {
            return [];
        }

        $rows = Database::connection()->query(
            'SELECT b.*, ' . self::AUTORE . ' FROM board_uses b INNER JOIN users u ON u.id = b.user_id
             WHERE b.resource_id IN (' . implode(',', array_map('intval', $resourceIds)) . ')
             ORDER BY b.created_at, b.id'
        )->fetchAll();
        $per = [];

        foreach ($rows as $r) {
            $per[(int) $r['resource_id']][] = $r;
        }

        return $per;
    }

    /** @return array<string, mixed>|null */
    public static function uso(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM board_uses WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /** Scrive o corregge il racconto di questa persona. */
    public static function salvaUso(int $resourceId, int $userId, string $testo): void
    {
        Database::connection()->prepare(
            'INSERT INTO board_uses (resource_id, user_id, body) VALUES (:r, :u, :b)
             ON DUPLICATE KEY UPDATE body = VALUES(body), updated_at = NOW()'
        )->execute(['r' => $resourceId, 'u' => $userId, 'b' => $testo]);
    }

    public static function nascondiUso(int $id, ?int $chi): void
    {
        Database::connection()->prepare('UPDATE board_uses SET hidden_at = :h, hidden_by = :b WHERE id = :id')
            ->execute(['id' => $id, 'h' => $chi === null ? null : date('Y-m-d H:i:s'), 'b' => $chi]);
    }

    // -----------------------------------------------------------------
    // Chi modera
    // -----------------------------------------------------------------

    /**
     * Gli studenti dei gruppi di questo tutor che hanno accesso al corso:
     * sono quelli di cui modera proposte e racconti.
     *
     * @return list<int>
     */
    public static function studentiDelTutor(int $tutorId, int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT gm.user_id
             FROM group_members gm
             INNER JOIN `groups` g ON g.id = gm.group_id AND g.tutor_id = :t
             INNER JOIN group_course_access gca ON gca.group_id = g.id AND gca.course_id = :c'
        );
        $stmt->execute(['t' => $tutorId, 'c' => $courseId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Chi riceve l'avviso di una proposta: i tutor attivi dei gruppi dello
     * studente in quel corso; se non ce n'e' nessuno, gli amministratori.
     *
     * @return list<array{email: string, full_name: string}>
     */
    public static function destinatari(int $studentId, int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT t.email, t.full_name
             FROM group_members gm
             INNER JOIN `groups` g ON g.id = gm.group_id
             INNER JOIN group_course_access gca ON gca.group_id = g.id AND gca.course_id = :c
             INNER JOIN users t ON t.id = g.tutor_id AND t.is_active = 1
             WHERE gm.user_id = :s
             ORDER BY t.full_name, t.email'
        );
        $stmt->execute(['s' => $studentId, 'c' => $courseId]);
        $tutor = $stmt->fetchAll();

        return $tutor !== [] ? $tutor : QuestionModel::adminRecipients();
    }
}
