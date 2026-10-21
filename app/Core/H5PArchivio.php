<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Apre un file `.h5p` caricato (un archivio zip) in una cartella di
 * `storage/h5p`, con le regole di `H5P`: ogni voce controllata una per una,
 * scritta da Pistacchio e non da `ZipArchive::extractTo` (che userebbe i
 * nomi dell'archivio cosi' come sono), e il totale limitato in numero e in
 * peso prima di scrivere qualunque cosa.
 *
 * Le cartelle si chiamano «q{questionario}-{casuale}»: un contenuto nuovo
 * va in una cartella nuova, e l'indirizzo dei file cambia con lui (niente
 * file vecchi nella cache del browser dopo una sostituzione).
 */
final class H5PArchivio
{
    public const RADICE = __DIR__ . '/../../storage/h5p';

    /** Se si puo' aprire un .h5p: serve l'estensione zip di PHP. */
    public static function disponibile(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    /**
     * Apre l'archivio in una cartella nuova e la restituisce, con il titolo
     * e il tipo di contenuto. Un archivio che non va bene non lascia niente
     * sul disco.
     *
     * @return array{dir: string, titolo: string, libreria: string, principale: string}
     * @throws \RuntimeException con la frase da mostrare a chi ha caricato
     */
    public static function apri(string $file, int $quizId): array
    {
        if (!self::disponibile()) {
            throw new \RuntimeException('Per i contenuti H5P serve l\'estensione zip di PHP, che su questo server non c\'è.');
        }

        $zip = new \ZipArchive();

        if ($zip->open($file) !== true) {
            throw new \RuntimeException('Il file non è un pacchetto H5P: non si apre come archivio. Esportalo da Lumi come «.h5p».');
        }

        try {
            $voci = self::voci($zip);
            $json = $zip->getFromName('h5p.json');
            $descrizione = is_string($json) ? H5P::descrizione($json) : null;

            if ($descrizione === null || !isset($voci['content/content.json'])) {
                throw new \RuntimeException('Il file non è un pacchetto H5P: mancano h5p.json o il contenuto. Esportalo da Lumi come «.h5p».');
            }

            $tipo = H5P::senzaPunteggio($descrizione['principale']);

            if ($tipo !== null) {
                throw new \RuntimeException('Questo contenuto è di tipo «' . $tipo . '», che non dà un punteggio: come questionario '
                    . 'non verrebbe mai superato, e il modulo non risulterebbe mai completato. I contenuti senza punteggio '
                    . 'andranno nelle lezioni (non ancora disponibile). Come questionario serve un esercizio con domande, '
                    . 'per esempio Question Set, Drag the Words o Fill in the Blanks.');
            }

            $dir = 'q' . $quizId . '-' . bin2hex(random_bytes(6));
            $base = self::RADICE . '/' . $dir;

            if (!is_dir(self::RADICE) && !mkdir(self::RADICE, 0775, true) && !is_dir(self::RADICE)) {
                throw new \RuntimeException('La cartella dei contenuti H5P non si può creare sul server.');
            }

            try {
                foreach ($voci as $percorso => $indice) {
                    self::scrivi($zip, $indice, $base . '/' . $percorso);
                }
            } catch (\Throwable $e) {
                self::elimina($dir);

                throw $e instanceof \RuntimeException ? $e : new \RuntimeException('Il contenuto H5P non si è potuto salvare sul server.');
            }

            return ['dir' => $dir] + $descrizione;
        } finally {
            $zip->close();
        }
    }

    /** Il percorso assoluto di un file del contenuto, o null se non e' dentro la sua cartella. */
    public static function file(string $dir, string $percorso): ?string
    {
        if (preg_match('/^q\d+-[0-9a-f]{12}$/', $dir) !== 1 || H5P::voce($percorso) !== $percorso) {
            return null;
        }

        $base = realpath(self::RADICE . '/' . $dir);
        $vero = realpath(self::RADICE . '/' . $dir . '/' . $percorso);

        return $base !== false && $vero !== false && str_starts_with($vero, $base . DIRECTORY_SEPARATOR) && is_file($vero) ? $vero : null;
    }

    /** Toglie la cartella di un contenuto (sostituito, o questionario eliminato). */
    public static function elimina(?string $dir): void
    {
        if ($dir === null || preg_match('/^q\d+-[0-9a-f]{12}$/', $dir) !== 1) {
            return;
        }

        $base = self::RADICE . '/' . $dir;

        if (!is_dir($base)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }

        @rmdir($base);
    }

    /**
     * Le voci da estrarre: percorso pulito => indice nell'archivio. Prima di
     * scrivere qualunque cosa: una voce da rifiutare, troppi file o troppi
     * byte fermano tutto.
     *
     * @return array<string, int>
     */
    private static function voci(\ZipArchive $zip): array
    {
        if ($zip->numFiles > H5P::MASSIMO_FILE) {
            throw new \RuntimeException('Il pacchetto contiene troppi file (più di ' . H5P::MASSIMO_FILE . ').');
        }

        $voci = [];
        $byte = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                throw new \RuntimeException('Il pacchetto è danneggiato.');
            }

            $esito = H5P::voce((string) $stat['name']);

            if ($esito === H5P::RIFIUTA) {
                throw new \RuntimeException('Il pacchetto contiene un file non ammesso («' . mb_substr((string) $stat['name'], 0, 80) . '»): non è stato caricato.');
            }

            if ($esito === H5P::SALTA) {
                continue;
            }

            $byte += (int) $stat['size'];

            if ($byte > H5P::MASSIMO_BYTE) {
                throw new \RuntimeException('Il contenuto estratto supera i ' . (H5P::MASSIMO_BYTE / 1024 / 1024) . ' MB.');
            }

            $voci[$esito] = $i;
        }

        return $voci;
    }

    /**
     * Una voce sul disco, a pezzi: la dimensione dichiarata nell'archivio si
     * puo' falsificare, quindi il limite si riconta mentre si scrive.
     */
    private static function scrivi(\ZipArchive $zip, int $indice, string $dove): void
    {
        $cartella = dirname($dove);

        if (!is_dir($cartella) && !mkdir($cartella, 0775, true) && !is_dir($cartella)) {
            throw new \RuntimeException('Il contenuto H5P non si è potuto salvare sul server.');
        }

        $da = $zip->getStream((string) $zip->getNameIndex($indice));
        $a = fopen($dove, 'wb');

        if ($da === false || $a === false) {
            throw new \RuntimeException('Il contenuto H5P non si è potuto salvare sul server.');
        }

        $scritti = 0;

        while (!feof($da)) {
            $pezzo = fread($da, 65536);

            if ($pezzo === false) {
                break;
            }

            $scritti += strlen($pezzo);

            if ($scritti > H5P::MASSIMO_BYTE) {
                fclose($da);
                fclose($a);

                throw new \RuntimeException('Il contenuto estratto supera i ' . (H5P::MASSIMO_BYTE / 1024 / 1024) . ' MB.');
            }

            fwrite($a, $pezzo);
        }

        fclose($da);
        fclose($a);
    }
}
