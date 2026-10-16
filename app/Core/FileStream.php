<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Consegna un file dal disco rispondendo anche alle richieste a pezzi
 * (`Range`), che i lettori audio e video dei browser fanno per saltare a un
 * punto e che Safari pretende: senza `206 Partial Content` non fa partire
 * nemmeno un audio.
 *
 * Era un metodo privato del controller delle lezioni (video caricati sul
 * server); dal 07/10 la usa anche il benvenuto del tutor, quindi sta qui,
 * scritta una volta.
 */
final class FileStream
{
    /**
     * Il tipo lo dice chi chiama, dall'estensione che ha gia' controllato
     * (13/10): mai dedotto dal contenuto, che e' di chi ha caricato il file.
     * E `nosniff`, perche' il browser non lo deduca a sua volta.
     */
    public static function send(string $absolutePath, string $mime): void
    {
        // Si apre prima di mandare qualunque intestazione: un file che manca
        // (o una cartella al suo posto) e' un 404, uno che c'e' ma non si
        // legge (permessi) un 500 con la causa nel log. Prima il primo dava
        // due avvisi e un errore fatale, il secondo un 200 vuoto e un fatale.
        $stream = is_file($absolutePath) ? @fopen($absolutePath, 'rb') : false;
        $stat = $stream === false ? false : fstat($stream);

        if ($stream === false || $stat === false) {
            $presente = is_file($absolutePath);

            if ($presente) {
                error_log('[FileStream] File presente ma non leggibile: ' . $absolutePath);
            }

            if (is_resource($stream)) {
                fclose($stream);
            }

            http_response_code($presente ? 500 : 404);
            header('Content-Type: text/plain; charset=utf-8');
            echo $presente ? 'Il file non si può leggere sul server.' : 'File non trovato.';

            return;
        }

        $size = (int) $stat['size'];

        $start = 0;
        $end = $size - 1;

        header('Accept-Ranges: bytes');
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');

        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
            if ($matches[1] === '' && $matches[2] !== '') {
                // «bytes=-N» vuol dire gli ultimi N byte (RFC 9110, 14.1.2),
                // non i byte da 0 a N: e' cosi' che un lettore chiede la coda
                // del file. N piu' grande del file vale tutto il file; N = 0
                // non chiede niente, e lo dice il 416 qui sotto.
                $start = max(0, $size - (int) $matches[2]);
                $end = (int) $matches[2] === 0 ? -1 : $size - 1;
            } else {
                $start = $matches[1] === '' ? 0 : (int) $matches[1];
                $end = $matches[2] === '' ? $size - 1 : min((int) $matches[2], $size - 1);
            }

            if ($start > $end || $start >= $size) {
                fclose($stream);
                header('Content-Range: bytes */' . $size);
                http_response_code(416);
                return;
            }

            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }

        header('Content-Length: ' . ($end - $start + 1));

        fseek($stream, $start);
        $bytesLeft = $end - $start + 1;

        while ($bytesLeft > 0 && !feof($stream)) {
            $read = (int) min(1024 * 1024, $bytesLeft);
            echo fread($stream, $read);
            flush();
            $bytesLeft -= $read;
        }

        fclose($stream);
    }
}
