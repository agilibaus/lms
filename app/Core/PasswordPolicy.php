<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Che cosa deve avere una password perche' sia accettata.
 *
 * Sta in un punto solo, e da qui passano tutti e tre i luoghi in cui una
 * password viene scelta — cambio dal profilo, reimpostazione via email,
 * registrazione autonoma — piu' il generatore delle temporanee, che deve
 * produrre password che la regola accetta. Prima la lunghezza minima era
 * ripetuta in tre classi, ed e' cosi' che nascono le differenze
 * (pistacchio-lms.md Sezione 4).
 *
 * **La frase mostrata e la regola applicata sono la stessa cosa**: `HINT` e
 * `problem()` stanno qui accanto proprio per non poter divergere. Una pagina
 * che promette una regola non applicata, o che ne applica una non detta, e'
 * peggio di nessuna regola.
 *
 * I simboli restano ammessi in piu', non al posto: la regola chiede che
 * almeno una lettera e almeno una cifra ci siano, non che il resto sia
 * vietato. Vietarli toglierebbe password buone senza aggiungere niente.
 */
class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    /** La frase da mostrare accanto ai campi. Dice esattamente cio' che `problem()` verifica. */
    public const HINT = 'Almeno ' . self::MIN_LENGTH . ' caratteri alfanumerici: '
        . 'servono almeno una lettera e almeno una cifra.';

    /**
     * Il motivo per cui la password non va bene, oppure null se va bene.
     *
     * Restituisce una frase e non un booleano perche' chi chiama deve poter
     * dire *cosa* manca: "non valida" manda la persona a indovinare.
     */
    public static function problem(string $password): ?string
    {
        if (strlen($password) < self::MIN_LENGTH) {
            return 'La password deve avere almeno ' . self::MIN_LENGTH . ' caratteri.';
        }

        if (preg_match('/[a-zA-Z]/', $password) !== 1) {
            return 'La password deve contenere almeno una lettera.';
        }

        if (preg_match('/[0-9]/', $password) !== 1) {
            return 'La password deve contenere almeno una cifra.';
        }

        return null;
    }
}
