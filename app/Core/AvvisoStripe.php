<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Gli avvisi di Stripe (06/11, seconda patch dei corsi a pagamento): la firma
 * e le regole che decidono se un pagamento iscrive lo studente. Senza
 * database: i dati del corso e dello studente li passa chi chiama
 * (`StripeController`), cosi' le regole si provano da sole.
 *
 * LA FIRMA. Stripe manda l'intestazione `Stripe-Signature: t=...,v1=...`:
 * `v1` e' l'HMAC-SHA256 di «t.corpo» con la chiave degli avvisi (whsec_...).
 * Un avviso senza firma giusta si rifiuta; uno con la firma giusta ma piu'
 * vecchio di cinque minuti anche: e' la difesa di Stripe contro chi
 * rimanda un avviso vero registrato.
 */
final class AvvisoStripe
{
    /** Quanto puo' essere vecchio un avviso, in secondi (il valore di Stripe). */
    public const TOLLERANZA = 300;

    /** Gli eventi che possono iscrivere: il pagamento completato e, per i
     *  metodi che si confermano dopo (bonifico SEPA), la conferma. */
    public const EVENTI = ['checkout.session.completed', 'checkout.session.async_payment_succeeded'];

    public static function firmaValida(string $corpo, string $intestazione, string $chiave, int $adesso): bool
    {
        if ($chiave === '' || $intestazione === '') {
            return false;
        }

        $tempo = null;
        $firme = [];

        foreach (explode(',', $intestazione) as $parte) {
            [$k, $v] = array_pad(explode('=', trim($parte), 2), 2, '');

            if ($k === 't' && ctype_digit($v)) {
                $tempo = (int) $v;
            } elseif ($k === 'v1' && $v !== '') {
                $firme[] = $v;
            }
        }

        if ($tempo === null || $firme === [] || abs($adesso - $tempo) > self::TOLLERANZA) {
            return false;
        }

        $attesa = hash_hmac('sha256', $tempo . '.' . $corpo, $chiave);

        foreach ($firme as $firma) {
            if (hash_equals($attesa, $firma)) {
                return true;
            }
        }

        return false;
    }

    /** L'intestazione che Stripe manderebbe: per le prove. */
    public static function firma(string $corpo, string $chiave, int $tempo): string
    {
        return 't=' . $tempo . ',v1=' . hash_hmac('sha256', $tempo . '.' . $corpo, $chiave);
    }

    /**
     * Se l'avviso riguarda un pagamento da contare: uno degli eventi, e con il
     * pagamento gia' arrivato (un bonifico «completato» ma non ancora pagato
     * aspetta la sua conferma).
     *
     * @param array<string, mixed> $evento
     */
    public static function daContare(array $evento): bool
    {
        $tipo = (string) ($evento['type'] ?? '');
        $sessione = $evento['data']['object'] ?? null;

        if (!in_array($tipo, self::EVENTI, true) || !is_array($sessione)) {
            return false;
        }

        return $tipo === 'checkout.session.async_payment_succeeded'
            || ($sessione['payment_status'] ?? '') === 'paid';
    }

    /**
     * Il motivo per cui il pagamento non iscrive nessuno, o null se iscrive.
     * Nell'ordine in cui si controlla: chi lo legge deve sapere la prima
     * cosa da sistemare.
     *
     * @param array<string, mixed> $sessione l'oggetto `checkout.session` di Stripe
     * @param array<string, mixed>|null $corso il corso dell'identificativo del link, se c'e'
     * @param array<string, mixed>|null $studente lo studente del numero nel pulsante, se c'e'
     */
    public static function motivo(array $sessione, bool $prova, ?array $corso, ?array $studente, bool $giaIscritto): ?string
    {
        $vero = (bool) ($sessione['livemode'] ?? false);

        if ($vero && $prova) {
            return 'Pagamento vero con la modalità di prova accesa.';
        }

        if (!$vero && !$prova) {
            return 'Pagamento di prova con la modalità di prova spenta.';
        }

        if ($corso === null) {
            return 'Corso non riconosciuto: nessun corso a pagamento ha questo link di pagamento.';
        }

        if ($studente === null) {
            return 'Studente non riconosciuto: il pagamento non è partito da Pistacchio LMS.';
        }

        $prezzo = (int) ($corso['price_cents'] ?? 0);
        $prima = (int) ($sessione['amount_subtotal'] ?? -1);

        if (strtolower((string) ($sessione['currency'] ?? '')) !== 'eur' || $prima !== $prezzo) {
            return 'Importo diverso dal prezzo del corso: ' . self::euro($prima) . ' invece di '
                . CorsiAPagamento::prezzo($prezzo) . '.';
        }

        if ((int) ($corso['is_published'] ?? 0) !== 1) {
            return 'Il corso è in bozza: nessuno vi è iscritto finché non viene pubblicato.';
        }

        if ($giaIscritto) {
            return 'L’iscrizione c’era già: il pagamento va rimborsato su Stripe.';
        }

        return null;
    }

    private static function euro(int $centesimi): string
    {
        return $centesimi < 0 ? 'nessun importo' : CorsiAPagamento::prezzo($centesimi);
    }
}
