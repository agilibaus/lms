<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\PrivacyModel;

/**
 * Privacy (18/11, Alessandro): le regole senza database.
 *
 * L'informativa e' una comunicazione, non un consenso: nessuna casella da
 * spuntare e nessuna pagina che blocca. Il genitore la riceve quando ci da'
 * i suoi dati — il modulo di registrazione o l'email «Il tuo accesso» — e
 * la ritrova alla voce «Privacy» in fondo alla pagina di accesso e nel
 * profilo. Quando cambia, un avviso lo dice una volta sulla pagina dei corsi.
 */
final class Privacy
{
    public const PAGINA = '/privacy';

    /**
     * I tag del testo dell'informativa: quelli delle lezioni meno immagini,
     * video e segnaposti H5P. Titoli, elenchi, tabelle e collegamenti bastano.
     */
    public const TAG = [
        'p' => [], 'br' => [], 'hr' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [],
        'h2' => [], 'h3' => [], 'h4' => [],
        'ul' => [], 'ol' => ['start', 'type'], 'li' => [],
        'blockquote' => [],
        'a' => ['href', 'title', 'target', 'rel'],
        'table' => [], 'thead' => [], 'tbody' => [],
        'tr' => [], 'th' => ['colspan', 'rowspan', 'scope'], 'td' => ['colspan', 'rowspan'],
        'caption' => [],
    ];

    /**
     * Da dove arriva chi apre l'informativa, e il pulsante che lo riporta li'.
     * Il parametro `da` e' una chiave di questo elenco, mai un indirizzo: un
     * collegamento confezionato non puo' mandare il genitore altrove.
     */
    private const RITORNI = [
        'registrazione' => ['/register', '← Torna alla registrazione'],
        'accesso' => ['/login', '← Torna all’accesso'],
        'email' => ['/login', 'Vai all’accesso'],
        'profilo' => ['/profilo', '← Torna al profilo'],
        'avviso' => ['/', '← Torna a Pistacchio'],
    ];

    /**
     * Il pulsante della barra della pagina /privacy.
     *
     * Senza `da` (un indirizzo scritto a mano, un segnalibro) decide
     * l'accesso: chi e' dentro torna a Pistacchio, chi e' fuori va
     * all'accesso.
     *
     * @return array{0: string, 1: string} indirizzo e testo
     */
    public static function ritorno(?string $da, bool $collegato): array
    {
        if ($da !== null && isset(self::RITORNI[$da])) {
            return self::RITORNI[$da];
        }

        return $collegato ? self::RITORNI['avviso'] : self::RITORNI['email'];
    }

    public static function link(string $da): string
    {
        return self::PAGINA . '?da=' . rawurlencode($da);
    }

    /**
     * L'avviso sulla pagina dei corsi: c'e' una versione in vigore, e lo
     * studente non ha ancora ricevuto quella. Chi non ne ha mai ricevuta una
     * (gli account di prima di questa funzione) lo vede per la prima.
     */
    public static function avvisoDaMostrare(?int $comunicata, ?int $inVigore): bool
    {
        return $inVigore !== null && ($comunicata === null || $comunicata < $inVigore);
    }

    /**
     * La data entro cui cancellare l'account: lo stesso giorno del mese dopo.
     * Se quel giorno non c'e' (31 gennaio), l'ultimo del mese: PHP andrebbe
     * al 3 marzo, cioe' oltre il mese.
     */
    public static function entro(string $chiestaIl): string
    {
        $da = new \DateTimeImmutable($chiestaIl);
        $mese = $da->modify('first day of next month');
        $giorno = min((int) $da->format('j'), (int) $mese->format('t'));

        return $mese->setDate((int) $mese->format('Y'), (int) $mese->format('n'), $giorno)->format('Y-m-d H:i:s');
    }

    public static function scaduta(string $chiestaIl, int $adesso): bool
    {
        return strtotime(self::entro($chiestaIl)) < $adesso;
    }

    /**
     * La riga fissa in fondo all'email «Il tuo accesso», per gli studenti:
     * la aggiunge Pistacchio, non il testo modificabile, cosi' una modifica
     * al testo dell'email non puo' toglierla per sbaglio. Vuota finche'
     * l'informativa non e' pubblicata.
     */
    public static function rigaEmail(?int $inVigore): string
    {
        if ($inVigore === null) {
            return '';
        }

        return "Accedendo dichiari di aver preso visione della nostra Informativa sulla privacy:\n"
            . Url::to(self::link('email')) . "\n";
    }

    /** Il testo dell'Amministratore, ripulito. Null se resta vuoto. */
    public static function pulisci(?string $html): ?string
    {
        return HtmlSanitizer::clean($html, self::TAG);
    }

    /** Comodo per le viste: la versione in vigore, letta una volta per richiesta. */
    public static function inVigore(): ?int
    {
        static $id = false;

        if ($id === false) {
            $id = PrivacyModel::idInVigore();
        }

        return $id;
    }
}
