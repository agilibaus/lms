<?php

declare(strict_types=1);

use App\Core\GroupLogo;

/**
 * Le colonne di ogni taglio, in un posto solo.
 *
 * Ogni colonna e' [etichetta, tipo, funzione che rende la cella].
 *
 * **Il tipo non e' aspetto, e' significato.** `numero` manda la colonna a
 * destra, in cifre a larghezza fissa e con una larghezza uguale in tutte e
 * cinque le tabelle: e' quello che permette di confrontare una colonna con
 * un'occhiata. Prima i conteggi erano allineati a sinistra e cadevano in
 * punti diversi in ogni tabella — nella prima a 611, 740 e 917 px — quindi
 * le cifre non formavano colonne e la pagina sembrava, come l'ha descritta
 * Elena, «numeri in ordine sparso».
 *
 * L'etichetta finisce sia nell'intestazione sia in `data-label`, che sotto i
 * 500 px il CSS stampa davanti al valore: vengono dalla stessa stringa e non
 * possono divergere.
 *
 * @var callable(?string): string $esc
 * @return array<string, list<array{0: string, 1: string, 2: callable(array): string}>>
 */

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
        ['Corso', 'testo', $bozza],
        ['Iscritti', 'numero', static fn (array $r): string => (string) (int) $r['enrolled_count']],
        ['Completati', 'numero', static fn (array $r): string => (string) (int) $r['completed_count']],
        ['Certificati', 'numero', static fn (array $r): string => (string) (int) $r['certificate_count']],
    ],
    'groups' => [
        ['Gruppo', 'testo', $gruppoConLogo],
        ['Tutor', 'testo', static fn (array $r): string => $esc((string) ($r['tutor_name'] ?? '—'))],
        ['Membri', 'numero', static fn (array $r): string => (string) (int) $r['member_count']],
    ],
    'students' => [
        ['Studente', 'testo', static function (array $r) use ($esc): string {
            return $esc((string) $r['full_name'])
                . ((int) $r['is_active'] === 0 ? ' <span class="badge">disattivato</span>' : '');
        }],
        ['Email', 'testo', static fn (array $r): string => $esc((string) $r['email'])],
        ['Corsi', 'numero', static fn (array $r): string => (string) (int) $r['enrolled_count']],
        ['Certificati', 'numero', static fn (array $r): string => (string) (int) $r['certificate_count']],
    ],
    'live' => [
        ['Incontro', 'testo', static fn (array $r): string => $esc((string) $r['title'])],
        ['Quando', 'testo', static function (array $r) use ($esc): string {
            $inizio = strtotime((string) $r['starts_at']);

            return $inizio === false ? '—' : $esc(date('d/m/Y H:i', $inizio));
        }],
        ['Corso o gruppo', 'testo', static fn (array $r): string
            => $esc((string) ($r['course_title'] ?? $r['group_name'] ?? '—'))],
        // Numero anche se porta una barra: «3/12» va letto confrontandolo
        // con quello della riga sopra, come ogni altro conteggio.
        ['Presenti', 'numero', static fn (array $r): string
            => (int) $r['attended'] . '/' . (int) $r['expected']],
    ],
    'fruizione' => [
        ['Corso', 'testo', $bozza],
        ['Lezioni con video', 'numero', static fn (array $r): string => (string) (int) $r['lezioni_video']],
        ['Iscritti', 'numero', static fn (array $r): string => (string) (int) $r['iscritti']],
        ['Hanno aperto un video', 'numero', static fn (array $r): string => (string) (int) $r['avviati']],
    ],
];
