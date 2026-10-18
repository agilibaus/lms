<?php

declare(strict_types=1);

namespace App\Core;

/**
 * La versione di Pistacchio LMS (18/10, Alessandro), mostrata in
 * Impostazioni → «Informazioni su Pistacchio LMS».
 *
 * STA NEL FILE `VERSION`, nella cartella principale: maggiore.minore.patch.
 * Le prime due cifre le decide Alessandro quando un traguardo lo merita; la
 * terza e' il numero della patch che l'ha portata, e ogni patch la aggiorna.
 * Cosi' dalla pagina si legge subito quale patch e' installata.
 *
 * Si legge dal file e non da git: sul server di produzione la cartella .git
 * di solito non c'e'.
 */
final class Versione
{
    private const FILE = __DIR__ . '/../../VERSION';

    /** La versione installata, o null se il file manca o non dice una versione. */
    public static function corrente(): ?string
    {
        return self::da(self::FILE);
    }

    public static function da(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }

        $versione = trim((string) file_get_contents($file));

        return preg_match('/^\d+\.\d+\.\d+$/', $versione) === 1 ? $versione : null;
    }
}
