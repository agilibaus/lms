<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Privacy;
use App\Core\View;
use App\Models\PrivacyModel;

/**
 * Privacy (18/11, Alessandro), dalla parte dello studente: la pagina
 * pubblica dell'informativa, l'avviso di aggiornamento e la richiesta di
 * cancellazione dei dati dal profilo.
 */
class PrivacyController
{
    /**
     * /privacy: l'informativa in vigore, leggibile senza accedere.
     *
     * Con `?versione=` una versione precedente, solo per l'Amministratore:
     * le versioni passate si danno su richiesta, non si pubblicano.
     */
    public function show(array $params = []): void
    {
        $chiesta = isset($_GET['versione']) ? (int) $_GET['versione'] : null;
        $versione = PrivacyModel::inVigore();
        $precedente = false;

        if ($chiesta !== null) {
            if (!Auth::check() || !Auth::can('settings.manage')) {
                http_response_code(404);
                exit('Pagina non trovata.');
            }

            $versione = PrivacyModel::find($chiesta);
            $precedente = $versione !== null && $versione['id'] !== PrivacyModel::idInVigore();
        }

        [$tornaA, $tornaTesto] = $chiesta !== null
            ? ['/admin/settings/privacy', '← Privacy']
            : Privacy::ritorno(isset($_GET['da']) ? (string) $_GET['da'] : null, Auth::check());

        View::render('privacy/show', [
            'pageTitle' => 'Informativa sulla privacy',
            'versione' => $versione,
            'precedente' => $precedente,
            'tornaA' => $tornaA,
            'tornaTesto' => $tornaTesto,
        ], false);
    }

    /**
     * La x dell'avviso sulla pagina dei corsi: la versione in vigore e'
     * comunicata, e l'avviso non torna fino alla prossima.
     */
    public function chiudiAvviso(array $params = []): void
    {
        Auth::requireLogin();

        $inVigore = PrivacyModel::idInVigore();

        if ($inVigore !== null && Auth::hasRole('studente')) {
            PrivacyModel::segnaComunicata((int) Auth::id(), $inVigore);
        }

        header('Location: /');
        exit;
    }

    /** «Chiedi la cancellazione dei tuoi dati»: la pagina che spiega e chiede conferma. */
    public function cancellazioneForm(array $params = []): void
    {
        $this->soloStudenti();

        View::render('profile/cancellazione', [
            'pageTitle' => 'Chiedi la cancellazione dei tuoi dati',
        ]);
    }

    /**
     * La conferma: richiesta registrata e accesso chiuso subito. Si esce, e
     * la pagina di accesso dice che la richiesta e' arrivata.
     */
    public function chiediCancellazione(array $params = []): void
    {
        $this->soloStudenti();

        PrivacyModel::chiediCancellazione((int) Auth::id());
        Auth::logout();

        header('Location: /login?motivo=cancellazione');
        exit;
    }

    /**
     * Solo gli studenti: lo staff ha un rapporto di lavoro, non di
     * partecipazione ai corsi, e un Amministratore che si chiude fuori da
     * solo lascerebbe la piattaforma senza nessuno.
     */
    private function soloStudenti(): void
    {
        Auth::requireLogin();

        if (!Auth::hasRole('studente')) {
            http_response_code(403);
            exit('Accesso negato: la cancellazione dal profilo è per gli studenti.');
        }
    }
}
