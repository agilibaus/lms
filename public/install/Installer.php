<?php

declare(strict_types=1);

defined('LMS_INSTALLER') || exit('Accesso diretto non consentito.');

/**
 * Motore della procedura di installazione guidata.
 *
 * Volutamente autonomo: non usa l'autoload di Composer né le classi di app/,
 * perché deve poter girare anche prima di `composer install` e con un database
 * che non esiste ancora. Solo PDO, openssl e funzioni di base.
 */
class Installer
{
    public const MIN_PHP = '8.1.0';
    public const MIN_PASSWORD = 8;

    /** Estensioni indispensabili: senza, l'applicazione non parte. */
    private const REQUIRED_EXTENSIONS = ['pdo_mysql', 'mbstring', 'json'];

    /** Estensioni necessarie solo ad alcune funzioni (certificati, Google Meet). */
    private const OPTIONAL_EXTENSIONS = [
        'dom' => 'certificati PDF (Dompdf)',
        'gd' => 'certificati PDF (Dompdf)',
        'openssl' => 'sessioni live su Google Meet',
        'curl' => 'sessioni live su Google Meet',
    ];

    public function __construct(private string $projectRoot)
    {
    }

    // ---------------------------------------------------------------
    // Stato dell'installazione
    // ---------------------------------------------------------------

    public function lockFile(): string
    {
        return $this->projectRoot . '/storage/installed.lock';
    }

    public function envFile(): string
    {
        return $this->projectRoot . '/.env';
    }

    public function schemaFile(): string
    {
        return $this->projectRoot . '/database/schema.sql';
    }

    /**
     * L'installazione si considera conclusa se esiste il file di lock, oppure
     * se esiste un .env che punta a un database con almeno un utente: in
     * quest'ultimo caso l'installer si blocca comunque, anche senza lock.
     */
    public function isInstalled(): bool
    {
        if (is_file($this->lockFile())) {
            return true;
        }

        $env = $this->readEnv();

        if ($env === null) {
            return false;
        }

        try {
            $pdo = $this->connect(
                $env['DB_HOST'] ?? '127.0.0.1',
                $env['DB_NAME'] ?? '',
                $env['DB_USER'] ?? '',
                $env['DB_PASS'] ?? ''
            );
        } catch (\PDOException) {
            return false;
        }

        return $this->hasUsers($pdo);
    }

    /**
     * @return array<string, string>|null
     */
    public function readEnv(): ?array
    {
        if (!is_file($this->envFile())) {
            return null;
        }

        $values = [];

        foreach ((array) file($this->envFile(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim((string) $line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim(trim($value), "\"'");
        }

        return $values;
    }

    // ---------------------------------------------------------------
    // Requisiti
    // ---------------------------------------------------------------

    /**
     * @return array<int, array{label: string, ok: bool, blocking: bool, detail: string}>
     */
    public function requirements(): array
    {
        $checks = [];

        $checks[] = [
            'label' => 'PHP ' . self::MIN_PHP . ' o superiore',
            'ok' => version_compare(PHP_VERSION, self::MIN_PHP, '>='),
            'blocking' => true,
            'detail' => 'Versione rilevata: ' . PHP_VERSION,
        ];

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $checks[] = [
                'label' => 'Estensione ' . $extension,
                'ok' => extension_loaded($extension),
                'blocking' => true,
                'detail' => 'Necessaria al funzionamento di base.',
            ];
        }

        foreach (self::OPTIONAL_EXTENSIONS as $extension => $usedFor) {
            $checks[] = [
                'label' => 'Estensione ' . $extension,
                'ok' => extension_loaded($extension),
                'blocking' => false,
                'detail' => 'Serve per: ' . $usedFor . '. Senza, il resto funziona.',
            ];
        }

        $checks[] = [
            'label' => 'Scrittura del file .env',
            'ok' => $this->canWriteEnv(),
            'blocking' => false,
            'detail' => $this->canWriteEnv()
                ? 'La configurazione verrà salvata automaticamente.'
                : 'Cartella del progetto non scrivibile: al termine ti mostrerò il contenuto da incollare a mano in .env.',
        ];

        $storageWritable = is_writable($this->projectRoot . '/storage');

        $checks[] = [
            'label' => 'Cartella storage/ scrivibile',
            'ok' => $storageWritable,
            'blocking' => false,
            'detail' => $storageWritable
                ? 'Materiali, video e certificati potranno essere salvati.'
                : 'Senza permessi di scrittura, upload e certificati falliranno: esegui "chmod -R 775 storage/".',
        ];

        $vendorPresent = is_file($this->projectRoot . '/vendor/autoload.php');

        $checks[] = [
            'label' => 'Dipendenze Composer installate',
            'ok' => $vendorPresent,
            'blocking' => false,
            'detail' => $vendorPresent
                ? 'vendor/ presente.'
                : 'Manca vendor/: esegui "composer update" nella root del progetto, altrimenti l\'applicazione non si avvierà.',
        ];

        $checks[] = [
            'label' => 'File dello schema database',
            'ok' => is_readable($this->schemaFile()),
            'blocking' => true,
            'detail' => 'Atteso in database/schema.sql',
        ];

        return $checks;
    }

    /**
     * @param array<int, array{ok: bool, blocking: bool}> $checks
     */
    public function hasBlockingProblems(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['blocking'] && !$check['ok']) {
                return true;
            }
        }

        return false;
    }

    public function canWriteEnv(): bool
    {
        return is_file($this->envFile())
            ? is_writable($this->envFile())
            : is_writable($this->projectRoot);
    }

    // ---------------------------------------------------------------
    // Database
    // ---------------------------------------------------------------

    public function connect(string $host, string $database, string $user, string $password): \PDO
    {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $database);

        return new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * Si connette al server senza selezionare un database: serve per poterlo
     * creare quando non esiste ancora.
     */
    public function connectServer(string $host, string $user, string $password): \PDO
    {
        return new \PDO(sprintf('mysql:host=%s;charset=utf8mb4', $host), $user, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /**
     * Crea il database se manca. Il nome non è parametrizzabile in una query
     * preparata, quindi viene validato con una whitelist di caratteri.
     *
     * @throws \RuntimeException se il nome non è valido
     */
    public function createDatabase(\PDO $server, string $database): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
            throw new \RuntimeException(
                'Nome del database non valido: sono ammessi solo lettere, numeri e trattini bassi.'
            );
        }

        $server->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $database
        ));
    }

    public function isEmptyDatabase(\PDO $pdo): bool
    {
        return $pdo->query('SHOW TABLES')->fetchColumn() === false;
    }

    public function hasUsers(\PDO $pdo): bool
    {
        try {
            return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
        } catch (\PDOException) {
            // Tabella assente: database non ancora inizializzato.
            return false;
        }
    }

    /**
     * Importa schema.sql eseguendo le istruzioni una per una.
     *
     * @return int numero di istruzioni eseguite
     */
    public function importSchema(\PDO $pdo): int
    {
        $sql = (string) file_get_contents($this->schemaFile());
        $executed = 0;

        foreach ($this->splitStatements($sql) as $statement) {
            $pdo->exec($statement);
            $executed++;
        }

        return $executed;
    }

    /**
     * Divide uno script SQL in istruzioni, ignorando i punti e virgola che
     * compaiono dentro stringhe o commenti.
     *
     * @return string[]
     */
    public function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote !== null) {
                $current .= $char;

                // Sequenza di escape dentro una stringa: il carattere seguente
                // non chiude la stringa.
                if ($char === '\\' && $next !== '') {
                    $current .= $next;
                    $i++;
                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;
                continue;
            }

            // Commento di riga: -- oppure #
            if (($char === '-' && $next === '-') || $char === '#') {
                $newline = strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline;
                continue;
            }

            // Commento a blocco
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                continue;
            }

            if ($char === ';') {
                $statement = trim($current);

                if ($statement !== '') {
                    $statements[] = $statement;
                }

                $current = '';
                continue;
            }

            $current .= $char;
        }

        $tail = trim($current);

        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }

    // ---------------------------------------------------------------
    // Amministratore e configurazione
    // ---------------------------------------------------------------

    public function createAdmin(\PDO $pdo, string $email, string $password, string $fullName): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO users (email, password_hash, full_name, role, is_active)
             VALUES (:email, :password_hash, :full_name, \'admin\', 1)'
        );
        $stmt->execute([
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'full_name' => $fullName,
        ]);
    }

    /**
     * @param array<string, string> $values
     */
    public function buildEnv(array $values): string
    {
        $lines = [
            '# Configurazione generata dalla procedura di installazione',
            '# ' . date('d/m/Y H:i'),
            '',
            'DB_HOST=' . ($values['DB_HOST'] ?? '127.0.0.1'),
            'DB_NAME=' . ($values['DB_NAME'] ?? 'lms'),
            'DB_USER=' . ($values['DB_USER'] ?? ''),
            'DB_PASS=' . ($values['DB_PASS'] ?? ''),
            '',
            '# In produzione tieni APP_DEBUG=0: gli errori non devono finire a video',
            'APP_DEBUG=' . ($values['APP_DEBUG'] ?? '0'),
            'APP_URL=' . ($values['APP_URL'] ?? ''),
            'APP_TIMEZONE=' . ($values['APP_TIMEZONE'] ?? 'Europe/Rome'),
            '',
            '# Video: necessarie solo con provider bunny o cloudflare',
            'BUNNY_LIBRARY_ID=' . ($values['BUNNY_LIBRARY_ID'] ?? ''),
            'CLOUDFLARE_STREAM_CUSTOMER_CODE=' . ($values['CLOUDFLARE_STREAM_CUSTOMER_CODE'] ?? ''),
            '',
            '# Sessioni live su Google Meet: senza queste variabili il link Meet si inserisce a mano',
            'GOOGLE_SERVICE_ACCOUNT_JSON=' . ($values['GOOGLE_SERVICE_ACCOUNT_JSON'] ?? ''),
            'GOOGLE_IMPERSONATE_EMAIL=' . ($values['GOOGLE_IMPERSONATE_EMAIL'] ?? ''),
            'GOOGLE_CALENDAR_ID=' . ($values['GOOGLE_CALENDAR_ID'] ?? 'primary'),
            'GOOGLE_CALENDAR_TIMEZONE=' . ($values['GOOGLE_CALENDAR_TIMEZONE'] ?? 'Europe/Rome'),
            '',
        ];

        return implode("\n", $lines);
    }

    public function writeEnv(string $contents): bool
    {
        return file_put_contents($this->envFile(), $contents) !== false;
    }

    public function writeLock(): bool
    {
        $message = "Installazione completata il " . date('c') . "\n"
            . "Elimina questo file solo se vuoi rieseguire la procedura guidata.\n";

        return file_put_contents($this->lockFile(), $message) !== false;
    }
}
