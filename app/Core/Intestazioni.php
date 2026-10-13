<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Le intestazioni di sicurezza di ogni pagina (13/10, verifica di sicurezza).
 *
 * **Nessun altro sito puo' mostrare la piattaforma dentro una sua pagina.**
 * Prima si poteva: un sito esterno incorniciava una pagina dell'admin,
 * trasparente, sotto un suo pulsante, e il clic dell'admin finiva sul
 * comando vero (il «clickjacking»). Due intestazioni dicono la stessa cosa:
 * `frame-ancestors 'self'` per i browser di oggi, `X-Frame-Options` per i
 * vecchi. `'self'` e non `'none'`: la piattaforma resta libera di
 * incorniciare se stessa (l'editor delle lezioni usa una cornice sua).
 *
 * Le cornici che la piattaforma mostra — i video di YouTube, Vimeo, Bunny —
 * non c'entrano: questa regola dice chi puo' incorniciare noi, non chi
 * incorniciamo noi.
 */
final class Intestazioni
{
    public static function sicurezza(): void
    {
        header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy: frame-ancestors 'self'");
    }
}
