<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accesso dati per la tabella `users`.
 */
class UserModel
{
    /**
     * Recupera un utente (anche non attivo) a partire dall'email,
     * includendo l'hash della password — usato solo dal layer Auth.
     */
    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, email, password_hash, password_changed_at, full_name, role,
                    is_active, email_verified_at
             FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /**
     * Recupera un utente per id, senza l'hash della password.
     */
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, email, full_name, role, is_active, email_verified_at,
                    bio, phone, city, avatar_path, created_at
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /**
     * Dati del profilo compilati dall'utente stesso.
     */
    public static function updateProfile(int $id, string $fullName, ?string $bio, ?string $phone, ?string $city): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET full_name = :full_name, bio = :bio, phone = :phone, city = :city WHERE id = :id'
        );
        $stmt->execute([
            'full_name' => $fullName,
            'bio' => $bio,
            'phone' => $phone,
            'city' => $city,
            'id' => $id,
        ]);
    }

    /**
     * @param string|null $path percorso relativo a /storage, null per togliere l'immagine
     */
    public static function updateAvatar(int $id, ?string $path): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET avatar_path = :path WHERE id = :id');
        $stmt->execute(['path' => $path, 'id' => $id]);
    }

    /**
     * Elenco utenti, ordinato per nome (per pannello admin/gestione utenti).
     * Include il nome del tutor supervisore, dove presente.
     */
    public static function all(): array
    {
        return Database::connection()->query(
            'SELECT u.id, u.email, u.full_name, u.role, u.is_active, u.created_at,
                    GROUP_CONCAT(t.full_name ORDER BY t.full_name SEPARATOR \', \') AS supervising_tutor_name
             FROM users u
             LEFT JOIN assistant_tutors at ON at.assistant_id = u.id
             LEFT JOIN users t ON t.id = at.tutor_id
             GROUP BY u.id
             ORDER BY u.full_name'
        )->fetchAll();
    }

    /**
     * Elenco completo per l'export, ordinato per nome: i dati della
     * registrazione e quelli che l'utente ha aggiunto al profilo.
     *
     * La bio resta fuori di proposito (deciso il 30/09): e' testo libero
     * lungo e renderebbe il foglio scomodo da leggere. Dell'immagine si porta
     * solo se c'e', non il percorso, che fuori da Pistacchio non serve.
     */
    public static function allForExport(): array
    {
        return Database::connection()->query(
            'SELECT u.id, u.full_name, u.email, u.role, u.is_active, u.email_verified_at,
                    u.phone, u.city, u.avatar_path, u.created_at, u.updated_at,
                    GROUP_CONCAT(t.full_name ORDER BY t.full_name SEPARATOR \', \') AS supervising_tutor_name
             FROM users u
             LEFT JOIN assistant_tutors at ON at.assistant_id = u.id
             LEFT JOIN users t ON t.id = at.tutor_id
             GROUP BY u.id
             ORDER BY u.full_name'
        )->fetchAll();
    }

    /**
     * Utenti di uno o piu' ruoli (es. elenco tutor per la tendina, studenti da iscrivere).
     *
     * @param string[] $roles
     */
    public static function byRoles(array $roles): array
    {
        if ($roles === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $stmt = Database::connection()->prepare(
            'SELECT id, email, full_name, role, is_active
             FROM users WHERE role IN (' . $placeholders . ') ORDER BY full_name'
        );
        $stmt->execute(array_values($roles));

        return $stmt->fetchAll();
    }

    /**
     * Assistenti che affiancano un tutor.
     */
    public static function assistantsForTutor(int $tutorId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT u.id, u.email, u.full_name, u.is_active
             FROM assistant_tutors at
             INNER JOIN users u ON u.id = at.assistant_id AND u.role = \'assistente\'
             WHERE at.tutor_id = :tutor_id
             ORDER BY u.full_name'
        );
        $stmt->execute(['tutor_id' => $tutorId]);

        return $stmt->fetchAll();
    }

    /**
     * Tutor che un assistente affianca: possono essere piu' d'uno.
     *
     * @return int[]
     */
    public static function tutorIdsForAssistant(int $assistantId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT at.tutor_id
             FROM assistant_tutors at
             INNER JOIN users t ON t.id = at.tutor_id AND t.role = \'tutor\'
             WHERE at.assistant_id = :id
             ORDER BY at.tutor_id'
        );
        $stmt->execute(['id' => $assistantId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Riscrive i tutor di un assistente. Chi non e' assistente non ne ha: se
     * l'utente cambia ruolo, i legami vecchi spariscono invece di restare
     * appesi a un utente che non affianca piu' nessuno.
     *
     * @param int[] $tutorIds
     */
    public static function setAssistantTutors(int $userId, string $role, array $tutorIds): void
    {
        $db = Database::connection();
        $db->prepare('DELETE FROM assistant_tutors WHERE assistant_id = :id')->execute(['id' => $userId]);

        if ($role !== 'assistente') {
            return;
        }

        $insert = $db->prepare(
            'INSERT IGNORE INTO assistant_tutors (assistant_id, tutor_id)
             SELECT :assistant, id FROM users WHERE id = :tutor AND role = \'tutor\''
        );

        foreach (array_unique(array_map('intval', $tutorIds)) as $tutorId) {
            $insert->execute(['assistant' => $userId, 'tutor' => $tutorId]);
        }
    }

    public static function emailExists(string $email, ?int $exceptId = null): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM users WHERE email = :email AND (:except_id IS NULL OR id <> :except_id2) LIMIT 1'
        );
        $stmt->execute([
            'email' => $email,
            'except_id' => $exceptId,
            'except_id2' => $exceptId ?? 0,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    public static function update(
        int $id,
        string $email,
        string $fullName,
        string $role,
        bool $isActive
    ): void {
        // I tutor di un assistente non stanno piu' qui: vedi setAssistantTutors().
        $stmt = Database::connection()->prepare(
            'UPDATE users
             SET email = :email, full_name = :full_name, role = :role, is_active = :is_active
             WHERE id = :id'
        );
        $stmt->execute([
            'email' => $email,
            'full_name' => $fullName,
            'role' => $role,
            'is_active' => $isActive ? 1 : 0,
            'id' => $id,
        ]);
    }

    /**
     * Impronta della password, per confrontarla con quella digitata.
     *
     * Sta in un metodo suo e non fra i campi di `find()`: l'impronta serve in
     * un punto solo, e tenerla fuori dalla riga che gira per controller e
     * viste evita che finisca stampata da qualche parte per distrazione.
     */
    public static function passwordHash(int $id): ?string
    {
        $stmt = Database::connection()->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $hash = $stmt->fetchColumn();

        return is_string($hash) ? $hash : null;
    }

    /**
     * Cambia la password e segna l'istante del cambio.
     *
     * `password_changed_at` e' quello che fa cadere le altre sessioni: la
     * sessione ne tiene una copia presa all'accesso, e al primo confronto che
     * non torna viene chiusa. L'istante lo calcola MySQL con `NOW()` e non
     * PHP, perche' server e database possono stare su fusi diversi
     * (pistacchio-lms.md Sezione 5).
     *
     * @param bool $mustChange vero per una password generata da un admin:
     *                         l'utente dovra' sceglierne una sua al primo accesso.
     */
    public static function updatePassword(int $id, string $password, bool $mustChange = false): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users
                SET password_hash = :password_hash,
                    password_changed_at = NOW(),
                    must_change_password = :must_change
              WHERE id = :id'
        );
        $stmt->execute([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'must_change' => $mustChange ? 1 : 0,
            'id' => $id,
        ]);
    }

    /**
     * Le due sole colonne che servono a ogni richiesta per decidere se la
     * sessione e' ancora buona e se l'utente deve cambiare la password.
     *
     * Query minima di proposito: gira su ogni pagina di chi e' collegato.
     *
     * @return array{password_changed_at: ?string, must_change_password: int}|null
     */
    public static function passwordState(int $id): ?array
    {
        // `welcome_seen_at` viaggia con le altre due: `guardSession()` le
        // chiede tutte nello stesso momento, e una colonna in piu' in una
        // query che c'e' gia' costa zero, mentre una seconda query sarebbe
        // una query per richiesta per ogni persona collegata (§7.2: il
        // vincolo vero sono i 20 processi PHP, non il database).
        $stmt = Database::connection()->prepare(
            'SELECT password_changed_at, must_change_password, welcome_seen_at
               FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $stato = $stmt->fetch();

        return $stato ?: null;
    }

    /**
     * Segna il video di benvenuto come visto, adesso.
     *
     * `NOW()` del database e non `date()` di PHP: le due macchine possono
     * stare su fusi diversi, ed e' la trappola di §5 gia' pagata una volta
     * con i tempi di fruizione. Qui sbagliare costerebbe poco, ma la regola
     * vale per ogni data che finisce in tabella, non solo per quelle che si
     * confrontano.
     *
     * Scrive solo se la casella e' ancora vuota: chi riguarda il video non
     * sposta in avanti la data del proprio primo accesso.
     */
    public static function markWelcomeSeen(int $id): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET welcome_seen_at = NOW()
              WHERE id = :id AND welcome_seen_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
    }

    public static function setActive(int $id, bool $isActive): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET is_active = :is_active WHERE id = :id');
        $stmt->execute(['is_active' => $isActive ? 1 : 0, 'id' => $id]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Numero di amministratori attivi: serve a impedire di rimuovere l'ultimo admin.
     */
    public static function countActiveAdmins(?int $exceptId = null): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM users
             WHERE role = \'admin\' AND is_active = 1 AND (:except_id IS NULL OR id <> :except_id2)'
        );
        $stmt->execute(['except_id' => $exceptId, 'except_id2' => $exceptId ?? 0]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Numero di corsi creati dall'utente: `courses.created_by` e' ON DELETE RESTRICT,
     * quindi un autore di corsi non puo' essere eliminato.
     */
    public static function countCoursesCreated(int $id): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM courses WHERE created_by = :id');
        $stmt->execute(['id' => $id]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Crea un nuovo utente con password hashata (bcrypt via password_hash).
     */
    /**
     * @param bool $mustChangePassword vero per una password generata da un
     *                                 admin: l'utente dovra' sceglierne una
     *                                 sua al primo accesso.
     */
    public static function create(
        string $email,
        string $password,
        string $fullName,
        string $role = 'studente',
        bool $isActive = true,
        bool $emailVerified = true,
        bool $mustChangePassword = false
    ): int {
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (email, password_hash, password_changed_at, must_change_password,
                                full_name, role, is_active, email_verified_at)
             VALUES (:email, :password_hash, NOW(), :must_change,
                     :full_name, :role, :is_active,
                     CASE WHEN :email_verified = 1 THEN NOW() ELSE NULL END)'
        );
        $stmt->execute([
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'must_change' => $mustChangePassword ? 1 : 0,
            'full_name' => $fullName,
            'role' => $role,
            'is_active' => $isActive ? 1 : 0,
            // Un account creato dallo staff ha un indirizzo gia' noto: chiedere
            // una conferma avrebbe senso solo per chi si registra da solo.
            'email_verified' => $emailVerified ? 1 : 0,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    public static function markEmailVerified(int $id): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET email_verified_at = NOW() WHERE id = :id AND email_verified_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Amministratori e tutor da avvisare quando arriva una richiesta di iscrizione.
     */
    public static function staffForNotifications(): array
    {
        return Database::connection()->query(
            "SELECT id, email, full_name FROM users
             WHERE role IN ('admin','tutor') AND is_active = 1
             ORDER BY full_name"
        )->fetchAll();
    }
}
