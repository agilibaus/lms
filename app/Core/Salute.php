<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Pistacchio risponde? (23/11, la sorveglianza esterna): la pagina pubblica
 * `/salute` dice «ok» solo se il database risponde.
 *
 * Una connessione sua e non `Database::connection()`: quella, se il
 * database non c'e', chiude la richiesta con una pagina di errore, mentre
 * qui serve una risposta breve e un 503. Attesa massima di 3 secondi.
 */
final class Salute
{
    private const ATTESA = 3;

    /**
     * @param array{host?: string, port?: int}|null $prova un altro server, solo per le prove
     */
    public static function risponde(?array $prova = null): bool
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;%sdbname=%s;charset=utf8mb4',
                $prova['host'] ?? Env::get('DB_HOST', '127.0.0.1'),
                isset($prova['port']) ? 'port=' . (int) $prova['port'] . ';' : '',
                Env::get('DB_NAME', 'lms')
            );
            $pdo = new PDO($dsn, Env::get('DB_USER', 'root'), Env::get('DB_PASS', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => self::ATTESA,
            ]);

            return (string) $pdo->query('SELECT 1')->fetchColumn() === '1';
        } catch (\Throwable) {
            return false;
        }
    }
}
