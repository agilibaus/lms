<?php

declare(strict_types=1);

namespace App\Core;

use App\Auth\Auth;

/**
 * Il video di benvenuto: se esiste, e a chi va mostrato.
 *
 * PERCHE' UNA CLASSE E NON DUE RIGHE DENTRO AD `Auth`. Le domande sono tre
 * — c'e' un video configurato? questa persona l'ha gia' visto? questa
 * pagina e' una di quelle che il rimando non deve toccare? — e servono in
 * tre posti diversi: il controllo della sessione, la pagina del benvenuto e
 * il profilo, che offre di rivederlo. Scritte in tre posti direbbero tre
 * cose leggermente diverse, ed e' la storia di `CourseRights`, `LiveScope` e
 * `ReportSections`.
 *
 * COSA NON FA. Non registra quante volte il video viene riguardato: la
 * colonna dice **se**, non quante. Un conteggio sarebbe un registro di
 * abitudini di una persona, tenuto per sempre, che nessuno ha chiesto —
 * la stessa scelta gia' fatta per il calendario sottoscritto.
 */
class Welcome
{
    public const PAGE = '/benvenuto';

    /** Le chiavi in `settings`, scritte dalla pagina Impostazioni → Benvenuto. */
    public const KEYS = [
        'WELCOME_VIDEO_PROVIDER',
        'WELCOME_VIDEO_REF',
    ];

    /**
     * I provider ammessi.
     *
     * Niente `self_hosted`: quel ramo di `VideoEmbed` serve il file passando
     * dall'identificativo di una **lezione**, e il benvenuto non e' una
     * lezione. Offrirlo qui vorrebbe dire un secondo percorso di consegna
     * dei file, con il suo controllo degli accessi: non vale il prezzo per
     * un video solo.
     */
    public const PROVIDERS = [
        'none' => 'Nessuno — la pagina non compare',
        'bunny' => 'Bunny Stream',
        'cloudflare' => 'Cloudflare Stream',
    ];

    public static function provider(): string
    {
        $p = (string) Settings::get('WELCOME_VIDEO_PROVIDER', 'none');

        return isset(self::PROVIDERS[$p]) ? $p : 'none';
    }

    public static function videoRef(): string
    {
        return trim((string) Settings::get('WELCOME_VIDEO_REF', ''));
    }

    /**
     * C'e' qualcosa da mostrare.
     *
     * Senza video la pagina non esiste e nessuno viene rimandato da nessuna
     * parte: il benvenuto si accende mettendo il video, non con un
     * interruttore a parte che si puo' dimenticare acceso a vuoto.
     */
    public static function configurato(): bool
    {
        return self::provider() !== 'none' && self::videoRef() !== '';
    }

    /**
     * L'HTML del player. Il `lessonId` di `VideoEmbed` non serve a questi
     * due provider e vale 0.
     */
    public static function embed(): string
    {
        return VideoEmbed::render(self::provider(), self::videoRef(), 0);
    }

    /**
     * Le pagine che il rimando al benvenuto non deve toccare.
     *
     * L'uscita resta sempre aperta, come per il cambio password: chi e'
     * entrato per sbaglio con un account altrui deve poter andarsene senza
     * passare da una schermata di benvenuto. E la pagina stessa, ovviamente,
     * o il rimando girerebbe su se stesso.
     */
    public static function paginaEsente(string $percorso): bool
    {
        // `str_starts_with` e non l'uguaglianza: sotto `/benvenuto` c'e'
        // anche la POST che registra la visione, e dirottare **quella**
        // verso la pagina del benvenuto significa non poterla mai
        // registrare — il giro si chiude su se stesso. Trovato provando,
        // non leggendo.
        return str_starts_with($percorso, self::PAGE)
            || $percorso === '/logout'
            || $percorso === Auth::PASSWORD_PAGE;
    }
}
