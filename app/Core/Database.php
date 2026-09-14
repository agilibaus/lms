<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                Env::get('DB_HOST', '127.0.0.1'),
                Env::get('DB_NAME', 'lms')
            );

            try {
                self::$instance = new PDO(
                    $dsn,
                    Env::get('DB_USER', 'root'),
                    Env::get('DB_PASS', ''),
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
            } catch (PDOException $e) {
                error_log('[DB] Connessione fallita: ' . $e->getMessage());
                http_response_code(500);
                exit('Errore di connessione al database. Riprova più tardi.');
            }
        }

        return self::$instance;
    }
}
