<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Ordinamento delle tabelle dal nome della colonna.
 *
 * PERCHE' DAL SERVER E NON NEL BROWSER. Il clic su un'intestazione ricarica
 * la pagina con `?ordina=colonna&verso=desc`. Costa una ricarica, e in
 * cambio da' tre cose che il riordino in JavaScript non puo' dare:
 *
 *   1. **Ordina tutte le righe, non quelle a schermo.** Gli elenchi dei
 *      report sono paginati a cinquanta righe: riordinando nel browser, «il
 *      punteggio piu' alto» sarebbe il piu' alto di quella pagina, non del
 *      report. Una risposta sbagliata che sembra giusta.
 *   2. **L'ordine sta nell'indirizzo**, quindi si salva nei preferiti e si
 *      manda a un collega.
 *   3. **Funziona senza JavaScript**, come il resto della piattaforma.
 *
 * COME SI USA, dalla vista che ha gia' le righe:
 *
 *     $ordine = Ordinamento::daRichiesta([
 *         'nome'  => ['full_name', Ordinamento::TESTO],
 *         'stato' => ['is_active', Ordinamento::NUMERO],
 *     ]);
 *     $utenti = $ordine->applica($utenti);
 *     // nella testata:  <?= $ordine->th('Nome', 'nome') ?>
 *
 * **Le chiavi dell'indirizzo non sono i nomi dei campi**: `nome` invece di
 * `full_name`. Quello che si scrive nell'indirizzo e' un'interfaccia
 * pubblica, e legarla ai nomi delle colonne del database vorrebbe dire sia
 * raccontarli a chi guarda, sia non poterli piu' cambiare senza rompere i
 * collegamenti salvati.
 *
 * **LO SPAREGGIO.** Una colonna puo' dichiarare un terzo elemento, il campo
 * da confrontare quando il primo e' uguale:
 *
 *         'studente' => ['full_name', Ordinamento::TESTO, 'email'],
 *
 * Serve alle colonne delle persone, dove due omonimi sono comuni. Senza,
 * le due righe restavano nell'ordine in cui arrivavano dal database, che
 * nessuno aveva deciso: la colonna non era ordinata del tutto, e — peggio —
 * con la paginazione a cinquanta righe due omonimi a cavallo di due pagine
 * potevano scambiarsi fra una richiesta e l'altra, facendo comparire uno
 * dei due due volte e l'altro mai. Trovato il 06/10 sul database vero di
 * Elena, che ha due «Elena Mazzoleni». L'email e' unica, quindi con lei
 * l'ordine e' sempre lo stesso; ed e' scritta nella cella sotto il nome,
 * quindi l'ordine si vede. Si confronta come testo e **nello stesso verso**
 * del primo campo: la colonna invertita si legge al contrario tutta intera.
 *
 * **Niente arriva dall'indirizzo al confronto.** La chiave ricevuta o e'
 * una di quelle dichiarate dalla vista, o viene ignorata: non esiste un
 * percorso in cui il testo scritto da chi naviga diventi un nome di campo.
 */
class Ordinamento
{
    public const TESTO = 'testo';
    public const NUMERO = 'numero';
    public const DATA = 'data';

    public const CRESCENTE = 'asc';
    public const DECRESCENTE = 'desc';

    /** @var array<string, array{0: string, 1: string, 2?: string}> chiave pubblica => [campo, tipo, spareggio] */
    private array $colonne;

    private ?string $chiave;

    private string $verso;

    /** @var array<string, string> gli altri parametri da tenere nei collegamenti */
    private array $altriParametri;

    /**
     * @param array<string, array{0: string, 1: string, 2?: string}> $colonne
     * @param array<string, mixed> $parametri di solito `$_GET`
     */
    private function __construct(array $colonne, array $parametri)
    {
        $this->colonne = $colonne;

        // `?ordina[]=nome` arriva come array, non come stringa: senza
        // questo controllo PHP lo converte e avvisa, e l'avviso finisce
        // stampato in cima alla pagina. Trovato provando indirizzi
        // inventati, non guardando il codice.
        $chiesta = is_string($parametri['ordina'] ?? null) ? $parametri['ordina'] : '';
        $this->chiave = isset($colonne[$chiesta]) ? $chiesta : null;

        $verso = is_string($parametri['verso'] ?? null) ? $parametri['verso'] : '';
        $this->verso = $verso === self::DECRESCENTE ? self::DECRESCENTE : self::CRESCENTE;

        $altri = [];

        foreach ($parametri as $nome => $valore) {
            // `pagina` sparisce apposta: cambiando l'ordine, la settima
            // pagina contiene righe diverse da quelle che si stavano
            // guardando. Si riparte dalla prima.
            if (in_array($nome, ['ordina', 'verso', 'pagina'], true) || !is_string($valore)) {
                continue;
            }

            $altri[(string) $nome] = $valore;
        }

        $this->altriParametri = $altri;
    }

    /**
     * @param array<string, array{0: string, 1: string, 2?: string}> $colonne chiave => [campo, tipo, spareggio]
     * @param array<string, mixed>|null $parametri
     */
    public static function daRichiesta(array $colonne, ?array $parametri = null): self
    {
        return new self($colonne, $parametri ?? $_GET);
    }

    /** La chiave su cui si sta ordinando, o null se nessuna. */
    public function chiave(): ?string
    {
        return $this->chiave;
    }

    public function verso(): string
    {
        return $this->verso;
    }

    /** Il campo del dato su cui si ordina, per chi ordina nel database. */
    public function campo(): ?string
    {
        return $this->chiave === null ? null : $this->colonne[$this->chiave][0];
    }

    /**
     * Le righe ordinate. Senza una chiave valida le restituisce **come
     * sono**: ogni elenco ha gia' un suo ordine naturale deciso dalla query
     * (gli incontri per data, le lezioni per posizione), e sostituirlo con
     * un ordine arbitrario al primo caricamento sarebbe un peggioramento.
     *
     * @param array<int, array<string, mixed>> $righe
     * @return array<int, array<string, mixed>>
     */
    public function applica(array $righe): array
    {
        if ($this->chiave === null) {
            return $righe;
        }

        [$campo, $tipo] = $this->colonne[$this->chiave];
        $spareggio = $this->colonne[$this->chiave][2] ?? null;
        $segno = $this->verso === self::DECRESCENTE ? -1 : 1;

        // `usort` e' stabile da PHP 8: le righe che hanno lo stesso valore
        // restano nell'ordine di partenza invece di rimescolarsi a ogni
        // caricamento.
        usort($righe, function (array $a, array $b) use ($campo, $tipo, $spareggio, $segno): int {
            $va = $a[$campo] ?? null;
            $vb = $b[$campo] ?? null;

            // I vuoti in fondo **in tutti e due i versi**. Un trattino che
            // sale in cima invertendo l'ordine non e' un'informazione:
            // sono le righe a cui quel dato manca, e stanno in coda.
            $ma = self::manca($va);
            $mb = self::manca($vb);

            if ($ma || $mb) {
                return $ma && $mb ? 0 : ($ma ? 1 : -1);
            }

            $esito = self::confronta($va, $vb, $tipo);

            if ($esito === 0 && $spareggio !== null) {
                $esito = self::confronta($a[$spareggio] ?? '', $b[$spareggio] ?? '', self::TESTO);
            }

            return $segno * $esito;
        });

        return $righe;
    }

    /**
     * L'intestazione di una colonna. Con una chiave e' un collegamento che
     * ordina; senza, e' un'intestazione come prima (la colonna dei comandi,
     * o una colonna calcolata che non si puo' ordinare).
     */
    public function th(string $etichetta, ?string $chiave = null, string $classe = ''): string
    {
        $attributi = ' scope="col" role="columnheader"';

        if ($classe !== '') {
            $attributi .= ' class="' . htmlspecialchars($classe) . '"';
        }

        if ($chiave === null || !isset($this->colonne[$chiave])) {
            return '<th' . $attributi . '>' . htmlspecialchars($etichetta) . '</th>';
        }

        $attiva = $chiave === $this->chiave;
        $crescente = $attiva && $this->verso === self::CRESCENTE;

        // Il secondo clic sulla stessa colonna inverte; il primo clic su
        // una colonna nuova parte sempre dal crescente, perche' «dalla A
        // alla Z» e' quello che ci si aspetta aprendo un elenco.
        $prossimo = $crescente ? self::DECRESCENTE : self::CRESCENTE;

        // `aria-sort` e' il modo in cui un lettore di schermo annuncia che
        // la tabella e' ordinata, e su quale colonna. Va sulla cella, non
        // sul collegamento.
        $stato = $attiva ? ($crescente ? 'ascending' : 'descending') : 'none';
        // La freccia c'è sempre, vuota dove non si ordina: così la colonna
        // non cambia larghezza al primo clic, trascinandosi dietro tutte
        // le altre.
        $freccia = '<span class="ordina-freccia" aria-hidden="true">'
            . ($attiva ? ($crescente ? '↑' : '↓') : '')
            . '</span>';

        // Il testo nascosto dice che cosa fa il collegamento: «Nome» da
        // solo, letto ad alta voce, non si distingue da un'intestazione
        // qualunque.
        $dice = $attiva
            ? ($crescente ? ': ordina in senso decrescente' : ': ordina in senso crescente')
            : ': ordina per questa colonna';

        return '<th' . $attributi . ' aria-sort="' . $stato . '">'
            . '<a class="ordina' . ($attiva ? ' ordina-attiva' : '') . '"'
            . ' href="' . htmlspecialchars($this->indirizzo($chiave, $prossimo)) . '">'
            . htmlspecialchars($etichetta)
            . '<span class="sr-only">' . htmlspecialchars($dice) . '</span>'
            . $freccia
            . '</a></th>';
    }

    /** L'indirizzo di questa stessa pagina con l'ordine chiesto. */
    public function indirizzo(string $chiave, string $verso): string
    {
        $q = $this->altriParametri;
        $q['ordina'] = $chiave;
        $q['verso'] = $verso;

        $percorso = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        return ($percorso === '' ? '/' : $percorso) . '?' . http_build_query($q);
    }

    private static function manca(mixed $v): bool
    {
        return $v === null || $v === '' || $v === '—';
    }

    private static function confronta(mixed $a, mixed $b, string $tipo): int
    {
        if ($tipo === self::NUMERO) {
            return (float) $a <=> (float) $b;
        }

        if ($tipo === self::DATA) {
            // Le date arrivano dal database come «2026-10-04 18:30:00»:
            // in quella forma il confronto fra stringhe e' gia' il
            // confronto fra date. `strtotime` serve per le altre.
            $ta = self::momento((string) $a);
            $tb = self::momento((string) $b);

            return $ta <=> $tb;
        }

        return self::confrontaTesto((string) $a, (string) $b);
    }

    private static function momento(string $v): int
    {
        $t = strtotime($v);

        return $t === false ? 0 : $t;
    }

    /**
     * Confronto fra testi secondo l'alfabeto italiano.
     *
     * Con l'estensione `intl` lo fa `Collator`, che sa che «à» sta fra «a»
     * e «b» e che «Zoe» viene prima di «àlberto» solo se si guardano i
     * byte. Dove `intl` non c'e' — puo' mancare su un hosting condiviso —
     * si toglie l'accento e si confronta senza maiuscole: non e' la stessa
     * cosa, ma e' molto piu' vicino del confronto fra byte, ed e' il
     * ripiego dichiarato.
     */
    private static function confrontaTesto(string $a, string $b): int
    {
        if (class_exists(\Collator::class)) {
            $c = new \Collator('it_IT');
            $esito = $c->compare($a, $b);

            if ($esito !== false) {
                return $esito;
            }
        }

        return self::ripiegoTesto($a, $b);
    }

    /**
     * Il confronto usato dove `intl` non c'e'. E' pubblico perche' sia
     * verificabile: senza, l'unico modo di provarlo sarebbe spegnere
     * l'estensione, e resterebbe il pezzo di codice che gira proprio dove
     * non lo possiamo guardare.
     */
    public static function ripiegoTesto(string $a, string $b): int
    {
        return strnatcasecmp(self::senzaAccenti($a), self::senzaAccenti($b));
    }

    private static function senzaAccenti(string $v): string
    {
        return strtr(mb_strtolower($v), [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
    }
}
