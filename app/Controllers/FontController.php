<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\FontLibrary;

/**
 * Serve i caratteri scaricati dal catalogo.
 *
 * Stanno in `storage/fonts/`, fuori dal document root come ogni altro file
 * caricato, quindi passano da qui invece che da Apache.
 *
 * **Questa e' l'unica rotta di file che non chiede l'accesso**, ed e'
 * necessario: il carattere serve anche alla pagina di accesso, cioe' a chi
 * l'accesso non l'ha ancora fatto. Non e' una concessione — un file di
 * carattere non contiene dati di nessuno — ma va detto, perche' la regola
 * generale del progetto e' l'opposto.
 */
class FontController
{
    public function serve(array $params): void
    {
        // **Il nome non arriva mai dall'indirizzo cosi' com'e'.** Si prende
        // la famiglia dal catalogo e si ricalcola il nome del file: un
        // indirizzo come `/assets/fonts/catalogo/..%2f..%2fconfig.php`
        // cerchera' una famiglia che non esiste e finira' in un 404, invece
        // di diventare un percorso.
        $chiesto = (string) ($params['file'] ?? '');
        $famiglia = null;

        foreach (array_keys(FontLibrary::catalogo()) as $nome) {
            if (FontLibrary::nomeFile((string) $nome) === $chiesto) {
                $famiglia = (string) $nome;
                break;
            }
        }

        if ($famiglia === null || !FontLibrary::presente($famiglia)) {
            http_response_code(404);
            echo 'Carattere non trovato.';
            return;
        }

        $percorso = FontLibrary::percorso($famiglia);

        header('Content-Type: font/woff2');
        header('Content-Length: ' . filesize($percorso));
        header('X-Content-Type-Options: nosniff');
        // Un carattere non cambia mai sotto lo stesso nome: se l'admin ne
        // sceglie un altro, cambia anche il nome del file. Tenerlo in cache
        // a lungo e' quindi sicuro, ed e' il file piu' pesante che una
        // pagina pubblica scarica.
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($percorso);
        exit;
    }
}
