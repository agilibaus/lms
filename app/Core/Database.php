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

                // L'orologio del database come quello di PHP (29/10, revisione
                // del codice). Le date si scrivono in ora italiana (APP_TIMEZONE,
                // config.php) e si confrontano con NOW() in SQL: su un MySQL in
                // UTC un modulo da aprire alle 9 si apriva alle 10 o alle 11, e
                // «Entra» compariva in ritardo. Si passa lo scarto di adesso
                // (+01:00, +02:00 con l'ora legale) e non il nome del fuso, che
                // MySQL conosce solo se il fornitore ha caricato le sue tabelle.
                self::$instance->exec("SET time_zone = '" . date('P') . "'");
            } catch (PDOException $e) {
                error_log('[DB] Connessione fallita: ' . $e->getMessage());
                http_response_code(500);
                // L'a capo in fondo serve ai comandi pianificati: il loro log
                // riceve questa frase, e senza la riga dopo ci si attaccava.
                exit("Errore di connessione al database. Riprova più tardi.\n");
            }
        }

        return self::$instance;
    }
}
