<?php

declare(strict_types=1);

namespace App\Core;

/**
 * «Tre parole» (21/10, Alessandro): alla fine di ogni modulo lo studente
 * scrive le prime tre parole che gli vengono — sensazioni, emozioni, stati
 * d'animo, pensieri, immagini. Diventano spunti per l'incontro su Meet.
 *
 * LE DECISIONI (21/10):
 * - **facoltative**: il riquadro resta sul corso finche' non risponde, ma
 *   non blocca il modulo dopo ne' il certificato;
 * - **corso per corso**: si attivano in Modifica corso, spente di partenza;
 * - **solo da adesso in poi**: si chiedono per i moduli completati da quando
 *   il corso le chiede (`courses.three_words_since`), non per quelli finiti
 *   prima, quando non sarebbero piu' d'istinto;
 * - **con il nome**: le leggono il tutor del gruppo e l'amministrazione, che
 *   le guardano anonime (la partenza: in un Meet con lo schermo condiviso i
 *   nomi non compaiono per sbaglio) o con il nome;
 * - **non si cambiano**: una volta inviate sono quelle.
 *
 * Qui le regole, senza database: i dati in `TreParoleModel`.
 */
final class TreParole
{
    /** La lunghezza di una parola: basta per una breve espressione, «mare calmo». */
    public const MASSIMO = 30;

    public const ANONIME = 'anonime';
    public const NOMI = 'nomi';

    /**
     * Una parola come si salva: senza spazi in piu', in minuscolo (cosi'
     * «Calma» e «calma» si contano insieme), al massimo MASSIMO caratteri.
     */
    public static function normalizza(string $parola): string
    {
        $parola = (string) preg_replace('/\s+/u', ' ', trim($parola));
        // Niente caratteri di controllo: arrivano solo incollando, e in una
        // parola non servono.
        $parola = (string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', $parola);

        return mb_substr(mb_strtolower($parola, 'UTF-8'), 0, self::MASSIMO, 'UTF-8');
    }

    /**
     * Le tre parole inviate, normalizzate; null se ne manca anche una sola.
     *
     * @param mixed $inviate di solito `$_POST['parole']`
     * @return array{0: string, 1: string, 2: string}|null
     */
    public static function daModulo(mixed $inviate): ?array
    {
        if (!is_array($inviate)) {
            return null;
        }

        $parole = [];

        for ($i = 0; $i < 3; $i++) {
            $p = $inviate[$i] ?? null;
            $p = is_string($p) ? self::normalizza($p) : '';

            if ($p === '') {
                return null;
            }

            $parole[] = $p;
        }

        return [$parole[0], $parole[1], $parole[2]];
    }

    /**
     * Se a uno studente vanno chieste le tre parole di un modulo: il corso le
     * chiede, il modulo e' completato, ed e' stato completato da quando il
     * corso le chiede.
     */
    public static function daChiedere(?string $completatoIl, ?string $chiesteDal): bool
    {
        return $chiesteDal !== null && $completatoIl !== null && $completatoIl >= $chiesteDal;
    }

    /**
     * Le parole che tornano, dalla piu' frequente; a parita' in ordine
     * alfabetico. Una parola ripetuta nella stessa terna conta una volta:
     * dice quante persone l'hanno scelta.
     *
     * @param list<array{0: string, 1: string, 2: string}> $terne
     * @return list<array{parola: string, volte: int}>
     */
    public static function ricorrenti(array $terne): array
    {
        $conta = [];

        foreach ($terne as $terna) {
            foreach (array_unique($terna) as $p) {
                $conta[$p] = ($conta[$p] ?? 0) + 1;
            }
        }

        $voci = [];

        foreach ($conta as $p => $n) {
            $voci[] = ['parola' => (string) $p, 'volte' => $n];
        }

        usort($voci, static fn (array $a, array $b): int
            => [$b['volte'], self::chiave($a['parola'])] <=> [$a['volte'], self::chiave($b['parola'])]);

        return $voci;
    }

    /**
     * Le terne in un ordine che non dice niente: ne' chi ha risposto prima,
     * ne' in che ordine sono iscritti. Sempre lo stesso per lo stesso modulo
     * (`$sale`), cosi' la pagina non si rimescola a ogni ricarica.
     *
     * @template T of array
     * @param list<T> $righe con un `id`
     * @return list<T>
     */
    public static function mescola(array $righe, string $sale): array
    {
        usort($righe, static fn (array $a, array $b): int
            => strcmp(hash('sha256', $sale . '|' . $a['id']), hash('sha256', $sale . '|' . $b['id'])));

        return $righe;
    }

    /** La vista chiesta nell'indirizzo: anonime, a meno di `?vista=nomi`. */
    public static function vista(mixed $chiesta): string
    {
        return $chiesta === self::NOMI ? self::NOMI : self::ANONIME;
    }

    /** Per confrontare le parole senza accenti: «perché» accanto a «perche». */
    private static function chiave(string $parola): string
    {
        $senza = class_exists(\Normalizer::class)
            ? (string) preg_replace('/\p{Mn}/u', '', (string) \Normalizer::normalize($parola, \Normalizer::FORM_D))
            : $parola;

        return $senza . "\0" . $parola;
    }
}
