<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Nome e cognome, e come compare una persona agli altri (07/10, chiesto da
 * Elena).
 *
 * LO STUDENTE SCEGLIE nel profilo come lo vedono gli altri studenti: con
 * nome e cognome (il predefinito), con il solo nome, o con le sole
 * iniziali. **Tutor e admin vedono sempre nome e cognome**, e ognuno vede
 * se stesso per intero. La scelta riguarda solo il nome: la foto resta una
 * scelta a parte, e chi non la carica compare con le iniziali di quello che
 * ha scelto di mostrare.
 *
 * Restano sempre completi i documenti ufficiali (certificato, report per la
 * Regione) e tutto cio' che vede lo staff.
 */
final class PersonName
{
    public const FULL = 'full';
    public const FIRST = 'first';
    public const INITIALS = 'initials';

    /** Le tre scelte, con l'etichetta del profilo. */
    public const SCELTE = [
        // In quest'ordine in ogni pagina che le mostra, dalla piu' riservata
        // alla piu' aperta (09/10, Elena: il profilo e il primo accesso le
        // mostravano in ordine opposto, perche' ciascuno aveva il suo).
        self::INITIALS => 'Solo le iniziali',
        self::FIRST => 'Solo il nome',
        self::FULL => 'Nome e cognome',
    ];

    public const MAX_CHARS = 100;

    /**
     * Come compare agli altri chi non ha ancora scelto (08/10, Elena): con le
     * sole iniziali. Protezione predefinita: nessuno e' esposto con nome e
     * cognome senza averlo deciso.
     */
    public const PREDEFINITO = self::INITIALS;

    /**
     * La scelta gia' selezionata nei moduli in cui lo studente sceglie (08/10,
     * Elena): «Solo il nome». E' un'altra cosa dal predefinito qui sopra:
     * quello e' cio' che vedono gli altri finche' lo studente non sceglie,
     * questa e' la proposta che trova quando sceglie.
     */
    public const PRESELEZIONATA = self::FIRST;

    /**
     * Divide un nome completo: la prima parola e' il nome, il resto il
     * cognome. Sbaglia con i nomi doppi («Maria Grazia Rossi»), ed e' per
     * questo che moduli e importazione vogliono i due campi separati. E' la
     * stessa divisione che la migrazione 2026_10_07_nome_cognome.sql ha fatto
     * in SQL sui nomi che c'erano gia'; qui serve ai test che partono da un
     * nome solo.
     */
    public static function split(string $completo): array
    {
        $parole = preg_split('/\s+/u', trim($completo), 2) ?: [''];

        return [$parole[0], $parole[1] ?? ''];
    }

    /**
     * Nome e cognome come li scrive una persona, ripuliti. Tutti e due
     * obbligatori: e' formazione finanziata, e il cognome serve.
     *
     * @return array{0: string, 1: string}
     * @throws \InvalidArgumentException con la frase da mostrare
     */
    public static function clean(string $nome, string $cognome): array
    {
        $nome = trim((string) preg_replace('/\s+/u', ' ', $nome));
        $cognome = trim((string) preg_replace('/\s+/u', ' ', $cognome));

        if ($nome === '') {
            throw new \InvalidArgumentException('Il nome è obbligatorio.');
        }

        if ($cognome === '') {
            throw new \InvalidArgumentException('Il cognome è obbligatorio.');
        }

        if (mb_strlen($nome) > self::MAX_CHARS || mb_strlen($cognome) > self::MAX_CHARS) {
            throw new \InvalidArgumentException('Nome e cognome possono essere lunghi al massimo ' . self::MAX_CHARS . ' caratteri.');
        }

        return [$nome, $cognome];
    }

    public static function display(string $scelta): string
    {
        return array_key_exists($scelta, self::SCELTE) ? $scelta : self::FULL;
    }

    /**
     * Le iniziali, con il punto: «M. R.». Una per ogni parola del nome e
     * del cognome che si mostrano, cosi' «Maria Grazia Rossi» resta
     * riconoscibile come «M. G. R.» a chi la conosce e a nessun altro.
     */
    public static function initials(string $nome, string $cognome): string
    {
        $iniziali = [];

        // Spazi e trattini separano le parole, l'apostrofo no: «D'Amico» e'
        // una parola sola, «D.», e non «D. A.».
        foreach (preg_split('/[\s-]+/u', trim($nome . ' ' . $cognome)) ?: [] as $parola) {
            if ($parola !== '') {
                $iniziali[] = mb_strtoupper(mb_substr($parola, 0, 1)) . '.';
            }
        }

        return implode(' ', $iniziali);
    }

    /**
     * Il nome di una persona come lo vede chi guarda.
     *
     * @param array{id:int, first_name:string, last_name:string, full_name:string, name_display?:string} $persona
     * @param bool $vedeTutto vero per lo staff, che vede sempre nome e cognome
     */
    public static function shown(array $persona, int $chiGuarda, bool $vedeTutto): string
    {
        if ($vedeTutto || (int) $persona['id'] === $chiGuarda) {
            return (string) $persona['full_name'];
        }

        // Non ancora scelto (NULL, o la stringa vuota in cui qualcuno l'ha
        // trasformato): le sole iniziali, la protezione predefinita (08/10).
        $scelta = (string) ($persona['name_display'] ?? '');

        return match ($scelta === '' ? self::INITIALS : self::display($scelta)) {
            self::FIRST => (string) $persona['first_name'],
            self::INITIALS => self::initials((string) $persona['first_name'], (string) $persona['last_name']),
            default => (string) $persona['full_name'],
        };
    }
}
