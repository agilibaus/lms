<?php

declare(strict_types=1);

use App\Core\GroupLogo;
use App\Core\Ordinamento;

/**
 * Le colonne di ogni taglio, in un posto solo.
 *
 * Ogni colonna è una voce con nome:
 *
 *   etichetta  il testo dell'intestazione, che finisce anche in `data-label`
 *   tipo       `testo` o `numero`
 *   cella      la funzione che rende il contenuto
 *   chiave     come si chiama nell'indirizzo quando si ordina (null: non si ordina)
 *   campo      il campo del dato su cui ordinare
 *   spareggio  il campo da confrontare a parita' del primo (Ordinamento,
 *              «lo spareggio»): l'email per le colonne delle persone
 *
 * **Il tipo non e' aspetto, e' significato.** `numero` manda la colonna a
 * destra, in cifre a larghezza fissa e con una larghezza uguale in tutte e
 * cinque le tabelle: e' quello che permette di confrontare una colonna con
 * un'occhiata. Prima i conteggi erano allineati a sinistra e cadevano in
 * punti diversi in ogni tabella — nella prima a 611, 740 e 917 px — quindi
 * le cifre non formavano colonne e la pagina sembrava, come l'ha descritta
 * Elena, «numeri in ordine sparso». Lo stesso tipo dice anche come si
 * ordina: per valore e non per come è scritto.
 *
 * **`campo` non è sempre quello che si vede.** La colonna «Quando» mostra
 * «04/10/2026 18:30» ma si ordina su `starts_at`, che è la data vera: sul
 * testo mostrato, marzo verrebbe prima di maggio perché «03» viene prima di
 * «05», e il 2025 dopo il 2026. «Presenti» mostra «3/12» e si ordina sul
 * numero dei presenti.
 *
 * L'etichetta finisce sia nell'intestazione sia in `data-label`, che sotto i
 * 500 px il CSS stampa davanti al valore: vengono dalla stessa stringa e non
 * possono divergere.
 *
 * @var callable(?string): string $esc
 * @return array<string, list<array{etichetta: string, tipo: string,
 *                                  cella: callable(array): string,
 *                                  chiave: ?string, campo: ?string,
 *                                  spareggio: ?string}>>
 */

/** Una colonna, con i valori che quasi sempre bastano. */
$colonna = static function (
    string $etichetta,
    string $tipo,
    callable $cella,
    ?string $chiave = null,
    ?string $campo = null,
    ?string $spareggio = null
): array {
    return [
        'etichetta' => $etichetta,
        'tipo' => $tipo,
        'cella' => $cella,
        'chiave' => $chiave,
        'campo' => $campo,
        'spareggio' => $spareggio,
    ];
};

/**
 * Il nome del gruppo con il suo simbolo davanti, come nell'elenco dei
 * gruppi del pannello: chi passa da una pagina all'altra riconosce il
 * gruppo dal colore prima di leggerlo, e qui mancava. Dove il logo non c'e'
 * resta il riquadro con le iniziali, tinto dall'identificativo — non un
 * buco, perche' una colonna in cui l'immagine a volte c'e' e a volte no
 * sembra rotta.
 *
 * Il simbolo e' decorativo: il nome e' li' accanto in chiaro, quindi
 * `alt=""` e `aria-hidden` perche' un lettore di schermo non lo legga due
 * volte.
 */
$gruppoConLogo = static function (array $riga) use ($esc): string {
    $logo = GroupLogo::url($riga);
    $id = (int) $riga['id'];
    $nome = (string) $riga['name'];

    $simbolo = $logo !== null
        ? '<img src="' . $esc($logo) . '" alt="" class="group-logo" loading="lazy">'
        : '<span class="group-logo group-logo-placeholder" aria-hidden="true"'
            . ' style="--logo-hue: ' . GroupLogo::hue($id) . ';">'
            . $esc(GroupLogo::initials($nome)) . '</span>';

    return '<span class="group-name">' . $simbolo . $esc($nome) . '</span>';
};

$bozza = static function (array $riga) use ($esc): string {
    return $esc((string) $riga['title'])
        . ((int) $riga['is_published'] === 0 ? ' <span class="badge">bozza</span>' : '');
};

return [
    'courses' => [
        $colonna('Corso', 'testo', $bozza, 'corso', 'title'),
        $colonna('Iscritti', 'numero', static fn (array $r): string
            => (string) (int) $r['enrolled_count'], 'iscritti', 'enrolled_count'),
        $colonna('Completati', 'numero', static fn (array $r): string
            => (string) (int) $r['completed_count'], 'completati', 'completed_count'),
        $colonna('Certificati', 'numero', static fn (array $r): string
            => (string) (int) $r['certificate_count'], 'certificati', 'certificate_count'),
    ],
    'groups' => [
        $colonna('Gruppo', 'testo', $gruppoConLogo, 'gruppo', 'name'),
        $colonna('Tutor', 'testo', static fn (array $r): string
            => $esc((string) ($r['tutor_name'] ?? '—')), 'tutor', 'tutor_name'),
        $colonna('Membri', 'numero', static fn (array $r): string
            => (string) (int) $r['member_count'], 'membri', 'member_count'),
    ],
    'students' => [
        $colonna('Studente', 'testo', static function (array $r) use ($esc): string {
            return $esc((string) $r['full_name'])
                . ((int) $r['is_active'] === 0 ? ' <span class="badge">disattivato</span>' : '');
        }, 'studente', 'full_name', 'email'),
        $colonna('Email', 'testo', static fn (array $r): string
            => $esc((string) $r['email']), 'email', 'email'),
        $colonna('Corsi', 'numero', static fn (array $r): string
            => (string) (int) $r['enrolled_count'], 'corsi', 'enrolled_count'),
        $colonna('Certificati', 'numero', static fn (array $r): string
            => (string) (int) $r['certificate_count'], 'certificati', 'certificate_count'),
    ],
    'live' => [
        $colonna('Incontro', 'testo', static fn (array $r): string
            => $esc((string) $r['title']), 'incontro', 'title'),
        // Mostra la data scritta all'italiana, ordina su quella vera.
        $colonna('Quando', Ordinamento::DATA, static function (array $r) use ($esc): string {
            $inizio = strtotime((string) $r['starts_at']);

            return $inizio === false ? '—' : $esc(date('d/m/Y H:i', $inizio));
        }, 'quando', 'starts_at'),
        // Non si ordina: la cella mostra il corso **oppure** il gruppo, e
        // sono due campi diversi. Ordinare su uno dei due metterebbe in
        // fondo tutte le righe dell'altro, con l'aria di un difetto.
        $colonna('Corso o gruppo', 'testo', static fn (array $r): string
            => $esc((string) ($r['course_title'] ?? $r['group_name'] ?? '—'))),
        // Numero anche se porta una barra: «3/12» va letto confrontandolo
        // con quello della riga sopra, come ogni altro conteggio.
        $colonna('Presenti', 'numero', static fn (array $r): string
            => (int) $r['attended'] . '/' . (int) $r['expected'], 'presenti', 'attended'),
    ],
    'fruizione' => [
        $colonna('Corso', 'testo', $bozza, 'corso', 'title'),
        $colonna('Lezioni con video', 'numero', static fn (array $r): string
            => (string) (int) $r['lezioni_video'], 'lezioni', 'lezioni_video'),
        $colonna('Iscritti', 'numero', static fn (array $r): string
            => (string) (int) $r['iscritti'], 'iscritti', 'iscritti'),
        $colonna('Hanno aperto un video', 'numero', static fn (array $r): string
            => (string) (int) $r['avviati'], 'avviati', 'avviati'),
    ],
];
