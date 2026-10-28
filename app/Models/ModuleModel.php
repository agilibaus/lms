<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per la tabella `modules`.
 */
class ModuleModel
{
    /**
     * I moduli del corso, ciascuno con `is_available`: 1 se e' gia' aperto.
     *
     * **Il confronto con l'ora si fa qui, in SQL, non in PHP.** E' la regola
     * di §5 gia' pagata una volta con i tempi di fruizione: l'orologio di PHP
     * e quello di MySQL possono stare su fusi diversi, e qui un'ora di
     * differenza vuol dire un modulo aperto o chiuso quando non doveva.
     * `NOW()` e `available_from` vengono dallo stesso orologio per
     * costruzione.
     *
     * `available_from` NULL vuol dire «sempre aperto»: e' il comportamento di
     * tutti i moduli che esistevano prima di questa funzione.
     */
    public static function forCourse(int $courseId, ?int $userId = null): array
    {
        // Con lo studente, la data e' la sua (`dataPer`, 26/10): quella del
        // suo gruppo, se ne ha una, altrimenti quella del corso.
        $data = $userId === null ? 'm.available_from' : self::dataPer((string) $userId);
        $stmt = Database::connection()->prepare(
            "SELECT m.id, m.course_id, m.title, m.objective, m.position, m.quiz_required, m.is_final,
                    {$data} AS available_from,
                    ({$data} IS NULL OR {$data} <= NOW()) AS is_available
             FROM modules m WHERE m.course_id = :course_id ORDER BY m.position, m.id"
        );
        $stmt->execute(['course_id' => $courseId]);

        return $stmt->fetchAll();
    }

    /**
     * I moduli del corso che sono contenuto, cioe' tutti tranne la verifica
     * finale (28/10, Alessandro): la verifica e' uno strumento del corso, non
     * qualcosa da imparare, quindi non si sceglie per un incontro, una
     * risorsa della Bacheca, una domanda all'esperto, e non chiede le Tre
     * parole.
     *
     * @return list<array<string, mixed>>
     */
    public static function contenuti(int $courseId, ?int $userId = null): array
    {
        return array_values(array_filter(
            self::forCourse($courseId, $userId),
            static fn (array $m): bool => empty($m['is_final'])
        ));
    }

    /**
     * La verifica finale del corso, o null (ce n'e' al massimo una, 28/10).
     * `$tranne` esclude un modulo: quello che si sta salvando.
     */
    public static function verificaFinale(int $courseId, ?int $tranne = null): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM modules WHERE course_id = :c AND is_final = 1 AND id <> :t ORDER BY id LIMIT 1'
        );
        $stmt->execute(['c' => $courseId, 't' => $tranne ?? 0]);
        $module = $stmt->fetch();

        return $module ?: null;
    }

    /**
     * La verifica finale sta sempre in fondo al corso (28/10): dopo ogni
     * modulo nuovo, ogni spostamento e ogni salvataggio la si rimette
     * dopo tutti gli altri. Senza verifica non fa niente.
     */
    public static function verificaInFondo(int $courseId): void
    {
        $verifica = self::verificaFinale($courseId);

        if ($verifica === null) {
            return;
        }

        $db = Database::connection();
        $stmt = $db->prepare('SELECT COALESCE(MAX(position), -1) FROM modules WHERE course_id = :c AND id <> :v');
        $stmt->execute(['c' => $courseId, 'v' => (int) $verifica['id']]);
        $ultima = (int) $stmt->fetchColumn();

        if ((int) $verifica['position'] <= $ultima) {
            $db->prepare('UPDATE modules SET position = :p WHERE id = :v')
                ->execute(['p' => $ultima + 1, 'v' => (int) $verifica['id']]);
        }
    }

    /**
     * LA DATA DI APERTURA PER UNO STUDENTE (26/10, Alessandro, conflitto 3).
     *
     * La data sul modulo e' quella di tutto il corso. Un gruppo — la classe —
     * ne puo' avere una sua (`group_module_dates`, dalla pagina del gruppo):
     * per lo studente vale quella del suo gruppo, se c'e', altrimenti quella
     * del corso. Se e' in piu' gruppi del corso con una data loro, vale la
     * piu' vicina. Contano solo i gruppi che hanno il corso.
     *
     * Prima la data era di calendario e uguale per tutti: una classe che
     * cominciava a marzo trovava aperti i moduli di novembre, senza le email
     * dell'apertura.
     *
     * E' un'espressione SQL sul modulo `$m` e sull'utente dato, un id
     * numerico o una colonna: la usano il blocco (`CourseAccess`), il riepilogo
     * dei corsi, l'agenda e le email dell'apertura. NULL = aperto da subito.
     */
    public static function dataPer(string $utente, string $m = 'm'): string
    {
        return "COALESCE((SELECT MIN(gmd.available_from)
                            FROM group_module_dates gmd
                            INNER JOIN group_members gm_d ON gm_d.group_id = gmd.group_id
                            INNER JOIN group_course_access gca_d
                                    ON gca_d.group_id = gmd.group_id AND gca_d.course_id = {$m}.course_id
                           WHERE gmd.module_id = {$m}.id AND gm_d.user_id = {$utente}),
                         {$m}.available_from)";
    }

    /**
     * Un solo modulo, con lo stesso `is_available` calcolato in SQL.
     *
     * `find()` resta com'era — la usano in molti punti che non c'entrano con
     * il rilascio — ma chi deve decidere se far entrare qualcuno usa questa.
     */
    public static function findWithAvailability(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT *, (available_from IS NULL OR available_from <= NOW()) AS is_available
             FROM modules WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $module = $stmt->fetch();

        return $module ?: null;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM modules WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $module = $stmt->fetch();

        return $module ?: null;
    }

    public static function create(
        int $courseId,
        string $title,
        bool $quizRequired = false,
        ?string $availableFrom = null,
        ?string $objective = null,
        bool $isFinal = false
    ): int {
        $db = Database::connection();

        $stmt = $db->prepare(
            'INSERT INTO modules (course_id, title, objective, position, quiz_required, is_final, available_from)
             VALUES (:course_id, :title, :objective, :position, :quiz_required, :is_final, :available_from)'
        );
        $stmt->execute([
            'course_id' => $courseId,
            'title' => $title,
            'objective' => $objective,
            'position' => self::nextPosition($courseId),
            // La verifica finale non blocca niente: dopo di lei non c'e'
            // nessun modulo (28/10).
            'quiz_required' => $quizRequired && !$isFinal ? 1 : 0,
            'is_final' => $isFinal ? 1 : 0,
            'available_from' => $availableFrom,
        ]);
        $id = (int) $db->lastInsertId();

        self::verificaInFondo($courseId);

        return $id;
    }

    public static function update(
        int $id,
        string $title,
        bool $quizRequired = false,
        ?string $availableFrom = null,
        ?string $objective = null,
        bool $isFinal = false
    ): void {
        $stmt = Database::connection()->prepare(
            'UPDATE modules
                SET title = :title, objective = :objective, quiz_required = :quiz_required,
                    is_final = :is_final, available_from = :available_from
              WHERE id = :id'
        );
        $stmt->execute([
            'title' => $title,
            'objective' => $objective,
            'quiz_required' => $quizRequired && !$isFinal ? 1 : 0,
            'is_final' => $isFinal ? 1 : 0,
            'available_from' => $availableFrom,
            'id' => $id,
        ]);

        $module = self::find($id);

        if ($module !== null) {
            self::verificaInFondo((int) $module['course_id']);
        }
    }

    public static function delete(int $id): void
    {
        // Le lezioni del modulo vengono rimosse a cascata (FK ON DELETE CASCADE).
        $stmt = Database::connection()->prepare('DELETE FROM modules WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private static function nextPosition(int $courseId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COALESCE(MAX(position), -1) + 1 AS next_position FROM modules WHERE course_id = :course_id'
        );
        $stmt->execute(['course_id' => $courseId]);

        return (int) $stmt->fetchColumn();
    }
    /**
     * Sposta di un posto su o giu' nell'ordine, scambiando la posizione con
     * il vicino. Stesso meccanismo dei materiali della lezione: uno solo in
     * tutta la piattaforma, cosi' si comporta sempre allo stesso modo.
     */
    public static function move(int $id, string $direction): void
    {
        $row = self::find($id);

        if ($row === null) {
            return;
        }

        $db = Database::connection();

        // Il vicino e' la riga adiacente nell'ordine di visualizzazione, non
        // quella con position +/- 1: dopo un'eliminazione le posizioni possono
        // avere dei buchi, e righe importate potrebbero condividere lo zero.
        $comparison = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'DESC' : 'ASC';

        $stmt = $db->prepare(
            'SELECT id, position, is_final FROM modules
             WHERE course_id = :parent AND (position, id) ' . $comparison . ' (:position, :id)
             ORDER BY position ' . $order . ', id ' . $order . ' LIMIT 1'
        );
        $stmt->execute([
            'parent' => (int) $row['course_id'],
            'position' => (int) $row['position'],
            'id' => $id,
        ]);
        $neighbour = $stmt->fetch();

        if (!$neighbour) {
            return; // gia' in cima o in fondo
        }

        // La verifica finale non si sposta, e nessun modulo scende sotto di
        // lei (28/10): sta sempre in fondo.
        if (!empty($row['is_final']) || !empty($neighbour['is_final'])) {
            return;
        }

        // Posizioni identiche (dati importati): rinumerare e' l'unico modo di
        // ottenere uno scambio visibile.
        if ((int) $neighbour['position'] === (int) $row['position']) {
            self::renumber((int) $row['course_id']);
            $row = self::find($id);
            $neighbour = self::find((int) $neighbour['id']);
        }

        // Le righe si rileggono dopo la rinumerazione: se nel frattempo una e'

        // stata eliminata, non c'e' piu' niente da scambiare.

        if ($row === null || $neighbour === null) {

            return;

        }


        $update = $db->prepare('UPDATE modules SET position = :position WHERE id = :id');
        $update->execute(['position' => (int) $neighbour['position'], 'id' => $id]);
        $update->execute(['position' => (int) $row['position'], 'id' => (int) $neighbour['id']]);
    }

    /**
     * Riassegna posizioni consecutive a partire da 0, mantenendo l'ordine attuale.
     */
    private static function renumber(int $parentId): void
    {
        $db = Database::connection();
        $update = $db->prepare('UPDATE modules SET position = :position WHERE id = :id');
        $position = 0;

        foreach (self::forCourse($parentId) as $row) {
            $update->execute(['position' => $position++, 'id' => (int) $row['id']]);
        }
    }

}
