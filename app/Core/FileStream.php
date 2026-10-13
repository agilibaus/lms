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
        $size = filesize($absolutePath);

        $start = 0;
        $end = $size - 1;

        header('Accept-Ranges: bytes');
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');

        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
            $start = $matches[1] === '' ? 0 : (int) $matches[1];
            $end = $matches[2] === '' ? $size - 1 : min((int) $matches[2], $size - 1);

            if ($start > $end || $start >= $size) {
                header('Content-Range: bytes */' . $size);
                http_response_code(416);
                return;
            }

            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }

        header('Content-Length: ' . ($end - $start + 1));

        $stream = fopen($absolutePath, 'rb');
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
