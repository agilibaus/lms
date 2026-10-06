<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\GroupPeers;
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

        View::render('profile/group', [
            'pageTitle' => self::titolo((string) $group['name']),
            'group' => $group,
            'tutor' => GroupModel::tutorForPage((int) $group['id']),
            'people' => GroupModel::peopleForPage((int) $group['id']),
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
