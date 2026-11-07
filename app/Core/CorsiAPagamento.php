<?php

declare(strict_types=1);

namespace App\Core;

/**
 * I corsi a pagamento (06/11, Alessandro; disegno del 31/10).
 *
 * «A pagamento» e' la quarta modalita' d'iscrizione: lo studente paga su
 * Stripe con il link di pagamento del corso, e l'avviso di Stripe lo iscrive
 * (patch successiva). Qui le regole che valgono gia' adesso:
 *
 * - l'interruttore generale, `PAYMENTS_ENABLED` in `settings`, spento
 *   all'inizio: spento, «A pagamento» non si sceglie, e un corso gia' a
 *   pagamento si comporta come «Chiusa» (fuori dal catalogo; lo iscrive solo
 *   l'admin, a mano);
 * - la modalita' la sceglie solo l'admin: il supervisore non la vede, e un
 *   corso gia' a pagamento salvato da lui resta com'e';
 * - un corso a pagamento non si assegna a un gruppo, e un corso assegnato a
 *   un gruppo, o con richieste di iscrizione in attesa, non diventa a
 *   pagamento: sono le vie per cui si entrerebbe senza pagare;
 * - prezzo, link e identificativo del link sono obbligatori.
 *
 * Le tre colonne stanno in `courses` (2026_11_06_corsi_a_pagamento.sql).
 */
final class CorsiAPagamento
{
    public const MODALITA = 'paid';

    /** '1' acceso; assente o qualunque altro valore, spento. */
    public const KEY = 'PAYMENTS_ENABLED';

    /**
     * La modalita' di prova (06/11): accesa, si accettano solo i pagamenti di
     * prova di Stripe; spenta, solo quelli veri. '0' spenta; assente o
     * qualunque altro valore, accesa: si comincia provando.
     */
    public const KEY_PROVA = 'PAYMENTS_TEST_MODE';

    /** La chiave degli avvisi di Stripe (whsec_...): non torna mai al browser. */
    public const KEY_SEGRETO = 'STRIPE_WEBHOOK_SECRET';

    /** L'indirizzo a cui Stripe manda gli avvisi. */
    public const INDIRIZZO_AVVISI = '/stripe/avvisi';

    /**
     * Dove Stripe rimanda lo studente dopo il pagamento (06/11): la pagina
     * d'attesa. `{CHECKOUT_SESSION_ID}` lo sostituisce Stripe con il codice
     * del pagamento; l'indirizzo e' lo stesso per tutti i link.
     */
    public const INDIRIZZO_RITORNO = '/pagamento-ricevuto';

    /** Quanti secondi, al massimo, aspetta la pagina d'attesa (06/11). */
    public const ATTESA_SECONDI = 5;

    public static function indirizzoRitorno(): string
    {
        return Url::to(self::INDIRIZZO_RITORNO) . '?sessione={CHECKOUT_SESSION_ID}';
    }

    /** Il prezzo piu' alto accettato, in centesimi: 99.999,99 €. */
    public const PREZZO_MASSIMO = 9_999_999;

    public static function attivi(): bool
    {
        return Settings::get(self::KEY, '0') === '1';
    }

    public static function inProva(): bool
    {
        return Settings::get(self::KEY_PROVA, '1') !== '0';
    }

    /**
     * L'indirizzo del pulsante «Iscriviti» di un corso a pagamento: il link di pagamento del
     * corso con il numero dello studente, da cui l'avviso di Stripe lo
     * riconosce, e la sua email gia' scritta (06/11).
     */
    public static function linkPerStudente(string $link, int $userId, string $email): string
    {
        $parametri = http_build_query(['client_reference_id' => (string) $userId, 'prefilled_email' => $email]);

        return $link . (str_contains($link, '?') ? '&' : '?') . $parametri;
    }

    /**
     * Se nel modulo del corso compare «A pagamento»: per l'admin con
     * l'interruttore acceso, e per chiunque se il corso lo e' gia' (senza,
     * salvare cambierebbe la modalita' senza che nessuno l'abbia scelta).
     */
    public static function nelModulo(bool $admin, ?string $modalitaAttuale): bool
    {
        return $modalitaAttuale === self::MODALITA || ($admin && self::attivi());
    }

    /**
     * Il prezzo scritto nel modulo, in centesimi, o null se non si legge.
     * Accetta «49», «49,00», «49.00», «1.250,50» e «1250,5»; il simbolo
     * dell'euro e gli spazi si ignorano.
     */
    public static function prezzoDaTesto(string $testo): ?int
    {
        $t = str_replace(['€', ' ', "\u{00A0}"], '', trim($testo));

        if ($t === '') {
            return null;
        }

        // Con la virgola, i punti sono separatori delle migliaia; senza,
        // un punto seguito da una o due cifre e' quello dei decimali.
        if (str_contains($t, ',')) {
            $t = str_replace('.', '', $t);
            $t = str_replace(',', '.', $t);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $t) === 1) {
            $t = str_replace('.', '', $t);
        }

        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $t, $m) !== 1) {
            return null;
        }

        $centesimi = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');

        return $centesimi > 0 && $centesimi <= self::PREZZO_MASSIMO ? $centesimi : null;
    }

    /** «49,00 €». */
    public static function prezzo(int $centesimi): string
    {
        return number_format($centesimi / 100, 2, ',', '.') . ' €';
    }

    /** Il prezzo come si rimette nel campo: «49,00». */
    public static function prezzoNelCampo(?int $centesimi): string
    {
        return $centesimi === null ? '' : number_format($centesimi / 100, 2, ',', '.');
    }

    public static function linkValido(string $link): bool
    {
        return strlen($link) <= 255
            && str_starts_with($link, 'https://')
            && filter_var($link, FILTER_VALIDATE_URL) !== false;
    }

    public static function identificativoValido(string $id): bool
    {
        return preg_match('/^plink_[A-Za-z0-9]{1,58}$/', $id) === 1;
    }

    /**
     * Che cosa impedisce a un corso di diventare a pagamento, o null.
     *
     * @param list<string> $gruppi i nomi dei gruppi a cui e' assegnato
     */
    public static function impedimento(array $gruppi, int $richiesteInAttesa): ?string
    {
        if ($gruppi !== []) {
            $nomi = self::elenco($gruppi);

            return count($gruppi) === 1
                ? 'Il corso è assegnato al gruppo ' . $nomi . ': va rimosso dal gruppo prima di renderlo a pagamento.'
                : 'Il corso è assegnato ai gruppi ' . $nomi . ': va rimosso dai gruppi prima di renderlo a pagamento.';
        }

        if ($richiesteInAttesa === 1) {
            return 'C’è 1 richiesta di iscrizione in attesa: va decisa prima di rendere il corso a pagamento.';
        }

        if ($richiesteInAttesa > 1) {
            return 'Ci sono ' . $richiesteInAttesa . ' richieste di iscrizione in attesa: vanno decise prima di rendere il corso a pagamento.';
        }

        return null;
    }

    /**
     * «Giallo», «Giallo e Verde», «Blu, Giallo e Verde».
     *
     * @param list<string> $nomi
     */
    private static function elenco(array $nomi): string
    {
        if (count($nomi) === 1) {
            return $nomi[0];
        }

        $ultimo = array_pop($nomi);

        return implode(', ', $nomi) . ' e ' . $ultimo;
    }
}
