<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Un campo di testo libero con un limite di caratteri: le due meta' del
 * limite in un posto solo (10/10).
 *
 *   - `html()` scrive il campo con il contatore sopra l'angolo in alto a
 *     destra, lo schema della presentazione del profilo: etichetta e
 *     contatore sulla stessa riga (`campo-contato`), `maxlength`, il
 *     contatore come prima descrizione del campo, niente `aria-live`. Lo
 *     script e' `quiz-open-count.js`, che la pagina carica.
 *   - `taglia()` e' la meta' sul server: un `maxlength` si toglie dagli
 *     strumenti per sviluppatori.
 *
 * Gli a capo si contano come li conta il campo, cioe' uno: il browser li
 * invia come \r\n, e contarli due volte taglierebbe la fine di un testo che
 * il campo aveva accettato. Per questo tutte e due le meta' li convertono.
 *
 * La regola «un campo con un limite ha il contatore» la controlla
 * `accessibilita.js` su ogni pagina.
 */
final class CampoContato
{
    /**
     * Il campo con il contatore. Il numero di partenza lo scrive il server
     * contando il testo gia' scritto: senza JavaScript resta fermo, ma non
     * dice una cosa falsa.
     */
    public static function html(
        string $id,
        string $etichetta,
        string $nome,
        int $massimo,
        string $valore,
        int $righe,
        bool $obbligatorio = false,
        string $segnaposto = ''
    ): string {
        $restano = max(0, $massimo - mb_strlen(self::aCapo($valore)));

        return '<div class="quiz-open-wrap campo-contato">'
            . '<div class="campo-contato-testa">'
            . '<label for="' . self::e($id) . '">' . self::e($etichetta) . '</label>'
            . '<span class="quiz-open-count" id="' . self::e($id) . '-resta" data-max="' . $massimo . '">'
            . $restano . ($restano === 1 ? ' carattere rimasto' : ' caratteri rimasti') . '</span>'
            . '</div>'
            . '<textarea id="' . self::e($id) . '" name="' . self::e($nome) . '" rows="' . $righe . '"'
            . ($obbligatorio ? ' required' : '')
            . ' maxlength="' . $massimo . '" class="quiz-open-answer" aria-describedby="' . self::e($id) . '-resta"'
            . ($segnaposto !== '' ? ' placeholder="' . self::e($segnaposto) . '"' : '')
            . '>' . self::e($valore) . '</textarea>'
            . '</div>';
    }

    /**
     * Il testo arrivato dal modulo: a capo convertiti, spazi ai bordi tolti,
     * tagliato al limite.
     */
    public static function taglia(string $valore, int $massimo): string
    {
        return mb_substr(trim(self::aCapo($valore)), 0, $massimo);
    }

    private static function aCapo(string $valore): string
    {
        return str_replace("\r\n", "\n", $valore);
    }

    private static function e(string $valore): string
    {
        return htmlspecialchars($valore, ENT_QUOTES);
    }
}
