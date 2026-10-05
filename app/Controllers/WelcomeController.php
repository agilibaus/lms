<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\View;
use App\Core\Welcome;
use App\Models\UserModel;

/**
 * La pagina del video di benvenuto.
 *
 * Si arriva qui in due modi, e la differenza conta: **dirottati** al primo
 * accesso da `Auth::guardSession()`, oppure **di propria volonta'** dal
 * collegamento nel profilo. Nel primo caso la pagina e' l'unica cosa che si
 * vede; nel secondo e' una pagina come le altre, con il menu al suo posto.
 */
class WelcomeController
{
    public function show(array $params = []): void
    {
        Auth::requireLogin();

        if (!Welcome::configurato()) {
            // Nessun video: niente da mostrare. Non e' un errore — e' lo
            // stato normale finche' l'admin non ne carica uno — quindi si
            // torna a casa invece di rispondere 404.
            header('Location: /');
            exit;
        }

        View::render('welcome/index', [
            'pageTitle' => 'Benvenuto',
            'embed' => Welcome::embed(),
            // «Da vedere» o «gia' visto»: cambia solo il pulsante in fondo,
            // perche' chi sta rivedendo non deve ritrovarsi un «Comincia» che
            // sembra registrare qualcosa.
            'primaVolta' => Auth::welcomePending(),
        ]);
    }

    /**
     * «Vai ai miei corsi»: registra la visione e porta via.
     *
     * In POST perche' **scrive**: una GET avrebbe voluto dire che basta
     * un'anteprima del collegamento, o un browser che precarica, per segnare
     * come visto un video che nessuno ha aperto. Il token CSRF lo verifica
     * il Router per tutte le POST, quindi qui non si ripete.
     */
    public function seen(array $params = []): void
    {
        Auth::requireLogin();

        // Solo la prima volta: chi sta rivedendo il video non riscrive la
        // data, che resta quella del primo accesso.
        if (Auth::welcomePending()) {
            UserModel::markWelcomeSeen((int) Auth::id());
        }

        header('Location: /');
        exit;
    }
}
