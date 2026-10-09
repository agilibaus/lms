<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\GroupPeers;
use App\Core\PersonName;
use App\Core\View;
use App\Models\GroupModel;

/**
 * La pagina di un gruppo vista da chi ne fa parte: il tutor al centro e i
 * compagni attorno, con la loro foto. Ci si arriva da «I miei gruppi» nel
 * profilo, non dalla barra laterale (deciso con Elena il 06/10: e' una
 * pagina che si apre ogni tanto, non un luogo dove si torna).
 *
 * Chi la puo' aprire lo decide `GroupPeers`, la stessa classe che decide chi
 * vede le foto.
 */
class GroupPageController
{
    public function show(array $params): void
    {
        Auth::requireLogin();

        $group = GroupModel::find((int) $params['id']);

        if ($group === null) {
            http_response_code(404);
            echo 'Gruppo non trovato.';
            return;
        }

        if (!GroupPeers::canViewGroup((int) Auth::id(), (int) $group['id'])) {
            http_response_code(403);
            echo 'Non fai parte di questo gruppo.';
            return;
        }

        // Come compaiono gli studenti (07/10): ciascuno come ha scelto nel
        // profilo — nome e cognome, solo il nome, solo le iniziali — tranne
        // per lo staff, che vede sempre tutto, e per se stessi. Il nome
        // mostrato prende il posto di `full_name`, cosi' la vista, le
        // iniziali della foto e la scheda della presentazione usano tutti lo
        // stesso. Il tutor al centro compare sempre per intero.
        $chiGuarda = (int) Auth::id();
        $vedeTutto = Auth::hasRole('admin', 'tutor');
        $people = array_map(
            static fn (array $p): array => [
                'full_name' => PersonName::shown($p, $chiGuarda, $vedeTutto),
                // Lo studente vede se stesso per intero (come in tutta la
                // piattaforma), e sotto, solo lui, come lo vedono gli altri
                // (08/10, Elena): e' l'unico modo di verificare la propria
                // scelta. Allo staff no: compare sempre per intero.
                'come_ti_vedono' => (int) $p['id'] === $chiGuarda && !$vedeTutto
                    ? PersonName::shown($p, 0, false)
                    : null,
            ] + $p,
            GroupModel::peopleForPage((int) $group['id'])
        );

        // In ordine di nome mostrato, non di nome vero: chi ha scelto le
        // iniziali non deve finire in mezzo a chi ha il suo stesso cognome.
        usort($people, static fn (array $a, array $b): int
            => strcmp(mb_strtolower((string) $a['full_name']), mb_strtolower((string) $b['full_name'])) ?: ((int) $a['id'] <=> (int) $b['id']));

        View::render('profile/group', [
            'pageTitle' => self::titolo((string) $group['name']),
            'group' => $group,
            'tutor' => GroupModel::tutorForPage((int) $group['id']),
            'people' => $people,
        ]);
    }

    /**
     * «Gruppo Verde», non «Verde» (chiesto da Elena il 06/10): i nomi dei
     * gruppi sono spesso un colore o una parola sola, che da soli in cima
     * alla pagina non dicono di che cosa si tratta. Se il nome comincia gia'
     * con «Gruppo» la parola non si ripete.
     */
    public static function titolo(string $nome): string
    {
        return preg_match('/^gruppo\b/iu', $nome) === 1 ? $nome : 'Gruppo ' . $nome;
    }
}
