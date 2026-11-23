<?php

declare(strict_types=1);

namespace App\Core;

use App\Auth\Auth;

/**
 * La pagina d'errore (23/11, Alessandro): fino ad allora una pagina non
 * trovata, un accesso negato o una sessione scaduta stampavano una riga di
 * testo su una pagina bianca, senza Pistacchio intorno.
 *
 * Ora chi apre una pagina vede un titolo che dice che cosa e' successo
 * («Pagina non trovata», «Accesso negato», «Sessione scaduta»…), la frase
 * di quel caso e il pulsante per tornare: dopo l'accesso dentro
 * l'applicazione, con la barra laterale; senza, nella cornice
 * dell'accesso.
 *
 * Solo per una pagina: la pagina la chiede il browser che ci naviga
 * (`Accept: text/html`). Un'immagine, un video, una chiamata da JavaScript
 * o un comando da terminale ricevono la frase come prima, in testo
 * semplice: non c'e' nessuno che la guardi come pagina, e chi la legge (un
 * `fetch`, un log) si aspetta la frase sola.
 */
final class Errore
{
    /** Il titolo di ogni codice: cio' che e' successo, in parole. */
    private const TITOLI = [
        400 => 'Richiesta non valida',
        403 => 'Accesso negato',
        404 => 'Pagina non trovata',
        410 => 'Non più disponibile',
        413 => 'File troppo grande',
        419 => 'Sessione scaduta',
        500 => 'Qualcosa non ha funzionato',
        502 => 'Qualcosa non ha funzionato',
    ];

    /** La frase quando il caso non ne ha una sua, o ripete solo il titolo. */
    private const FRASI = [
        403 => 'Non hai i permessi per aprire questa pagina.',
        404 => 'Questo indirizzo non corrisponde a nessuna pagina di Pistacchio: forse è scritto male, oppure la pagina è stata spostata o tolta.',
    ];

    public static function titolo(int $codice): string
    {
        return self::TITOLI[$codice] ?? 'Qualcosa non ha funzionato';
    }

    /**
     * La frase da mostrare sotto il titolo. «Accesso negato: permessi
     * insufficienti…» sotto il titolo «Accesso negato» lo direbbe due volte:
     * l'inizio uguale al titolo si toglie. Una frase che e' solo il titolo
     * («Pagina non trovata.», «Accesso negato.», «Non trovato.») lascia il
     * posto a quella generale.
     */
    public static function frase(int $codice, string $messaggio): string
    {
        $titolo = self::titolo($codice);
        $testo = trim($messaggio);
        $prefisso = $titolo . ':';

        if (mb_stripos($testo, $prefisso) === 0) {
            $testo = Etichetta::maiuscola(mb_substr($testo, mb_strlen($prefisso)));
        }

        $nudo = mb_strtolower(rtrim($testo, '.'));

        if ($testo === '' || $nudo === mb_strtolower($titolo) || $nudo === 'non trovato') {
            $testo = self::FRASI[$codice] ?? $testo;
        }

        return $testo;
    }

    /** Il browser che naviga chiede una pagina; il resto no. */
    public static function vuolePagina(): bool
    {
        return PHP_SAPI !== 'cli'
            && str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'text/html');
    }

    /**
     * Imposta il codice e mostra l'errore. Non esce: chi chiama prosegue come
     * prima (un `return` o un `exit`).
     */
    public static function pagina(int $codice, string $messaggio): void
    {
        http_response_code($codice);

        if (!self::vuolePagina()) {
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo $messaggio;

            return;
        }

        $dati = [
            'pageTitle' => self::titolo($codice),
            'messaggio' => self::frase($codice, $messaggio),
        ];

        // Se disegnare la pagina non riesce (il database che non risponde a
        // meta' strada), resta la frase: meglio di una pagina vuota.
        ob_start();
        try {
            if (Auth::check()) {
                View::render('errori/pagina', $dati);
            } else {
                View::render('errori/pubblica', $dati, false);
            }
            ob_end_flush();
        } catch (\Throwable $e) {
            ob_end_clean();
            echo htmlspecialchars($messaggio);
        }
    }
}
