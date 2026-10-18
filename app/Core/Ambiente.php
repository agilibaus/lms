<?php

declare(strict_types=1);

namespace App\Core;

/**
 * I dati per l'assistenza in «Informazioni su Pistacchio LMS» (18/10,
 * Alessandro): la versione di PHP, il database e la data di installazione.
 * Sono le prime cose che servono per capire un problema su un server.
 */
final class Ambiente
{
    private const LOCK = __DIR__ . '/../../storage/installed.lock';
    private const ENV = __DIR__ . '/../../.env';

    /** PHP come maggiore.minore.patch, senza le aggiunte del pacchetto. */
    public static function php(): string
    {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
    }

    /** Il database in uso, letto da lui («MySQL 8.0.30», «MariaDB 10.11.14»). */
    public static function database(): string
    {
        try {
            return self::nomeDatabase((string) Database::connection()->query('SELECT VERSION()')->fetchColumn());
        } catch (\Throwable $e) {
            return self::nomeDatabase('');
        }
    }

    /**
     * Da quello che risponde `SELECT VERSION()` al nome che si legge.
     * MariaDB lo dice nel testo, a volte dopo un finto «5.5.5-» messo per i
     * vecchi client; MySQL scrive solo il numero, con qualche aggiunta.
     */
    public static function nomeDatabase(string $versione): string
    {
        if (stripos($versione, 'mariadb') !== false && preg_match('/(?:^|-)(\d+\.\d+\.\d+)-MariaDB/i', $versione, $m) === 1) {
            return 'MariaDB ' . $m[1];
        }

        if (stripos($versione, 'mariadb') === false && preg_match('/^(\d+\.\d+\.\d+)/', $versione, $m) === 1) {
            return 'MySQL ' . $m[1];
        }

        return 'non nota';
    }

    /** La data di installazione, gg/mm/aaaa, o null se non si sa. */
    public static function installazione(): ?string
    {
        return self::installazioneDa(self::LOCK, self::ENV);
    }

    /**
     * Dal file che l'installer scrive alla fine («Installazione completata
     * il …»): la data scritta dentro, o in mancanza quella del file. Senza
     * installer (installazione a mano) la data del .env.
     */
    public static function installazioneDa(string $lock, string $env): ?string
    {
        clearstatcache();

        if (is_file($lock)) {
            if (preg_match('/(\d{4}-\d{2}-\d{2})T/', (string) file_get_contents($lock), $m) === 1) {
                return date('d/m/Y', (int) strtotime($m[1]));
            }

            return date('d/m/Y', (int) filemtime($lock));
        }

        return is_file($env) ? date('d/m/Y', (int) filemtime($env)) : null;
    }
}
