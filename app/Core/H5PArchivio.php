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
 * Le cartelle si chiamano «q{questionario}-{casuale}», o «l{lezione}-{casuale}»
 * per i contenuti delle lezioni (23/10): un contenuto nuovo
 * va in una cartella nuova, e l'indirizzo dei file cambia con lui (niente
 * file vecchi nella cache del browser dopo una sostituzione).
 */
final class H5PArchivio
{
    public const RADICE = __DIR__ . '/../../storage/h5p';

    /** Il nome di una cartella: «q» per i questionari, «l» per le lezioni (23/10). */
    private const NOME_CARTELLA = '/^[ql]\d+-[0-9a-f]{12}$/';

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
        return self::estrai($file, 'q' . $quizId, true);
    }

    /**
     * Come `apri()`, per un contenuto di una lezione (23/10): qualunque
     * tipo, anche senza punteggio, in una cartella «l{lezione}-{casuale}».
     *
     * @return array{dir: string, titolo: string, libreria: string, principale: string}
     * @throws \RuntimeException con la frase da mostrare a chi ha caricato
     */
    public static function apriPerLezione(string $file, int $lessonId): array
    {
        return self::estrai($file, 'l' . $lessonId, false);
    }

    /**
     * @return array{dir: string, titolo: string, libreria: string, principale: string}
     */
    private static function estrai(string $file, string $prefisso, bool $conPunteggio): array
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

            // Il codice del tipo di contenuto deve esserci: senza, il
            // riproduttore non sa disegnarlo (23/10, un pacchetto con il
            // solo h5p.json passava, e nella lezione restava un riquadro vuoto).
            if (!self::haLibreria($voci, $descrizione['principale'])) {
                throw new \RuntimeException('Il pacchetto non contiene il codice del contenuto (la libreria «'
                    . $descrizione['principale'] . '»). Esportalo da Lumi come file .h5p completo.');
            }

            $tipo = $conPunteggio ? H5P::senzaPunteggio($descrizione['principale']) : null;

            if ($tipo !== null) {
                throw new \RuntimeException('Questo contenuto è di tipo «' . $tipo . '», che non dà un punteggio: come questionario '
                    . 'non verrebbe mai superato, e il modulo non risulterebbe mai completato. I contenuti senza punteggio '
                    . 'si possono mettere in una lezione (Modifica lezione, «Contenuti interattivi H5P»). Come questionario serve un esercizio con domande, '
                    . 'per esempio Question Set, Drag the Words o Fill in the Blanks.');
            }

            $dir = $prefisso . '-' . bin2hex(random_bytes(6));
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

    /**
     * Se fra le voci c'e' la libreria principale: una cartella
     * «H5P.DragText-1.10» (o «H5P.DragText») con il suo `library.json`.
     *
     * @param array<string, int> $voci
     */
    private static function haLibreria(array $voci, string $principale): bool
    {
        foreach (array_keys($voci) as $percorso) {
            $cartella = strstr($percorso, '/library.json', true);

            if ($cartella !== false && !str_contains($cartella, '/')
                && ($cartella === $principale || preg_match('/^' . preg_quote($principale, '/') . '-\d+\.\d+$/', $cartella) === 1)) {
                return true;
            }
        }

        return false;
    }

    /** Il percorso assoluto di un file del contenuto, o null se non e' dentro la sua cartella. */
    public static function file(string $dir, string $percorso): ?string
    {
        if (preg_match(self::NOME_CARTELLA, $dir) !== 1 || H5P::voce($percorso) !== $percorso) {
            return null;
        }

        $base = realpath(self::RADICE . '/' . $dir);
        $vero = realpath(self::RADICE . '/' . $dir . '/' . $percorso);

        return $base !== false && $vero !== false && str_starts_with($vero, $base . DIRECTORY_SEPARATOR) && is_file($vero) ? $vero : null;
    }

    /** Toglie la cartella di un contenuto (sostituito, o questionario o lezione eliminati). */
    public static function elimina(?string $dir): void
    {
        if ($dir === null || preg_match(self::NOME_CARTELLA, $dir) !== 1) {
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
