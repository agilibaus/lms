<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Privacy;
use App\Core\View;
use App\Core\Xlsx;
use App\Models\DatiPersonaliModel;
use App\Models\PrivacyModel;
use App\Core\Errore;

/**
 * Privacy (18/11, Alessandro), dalla parte dello studente: la pagina
 * pubblica dell'informativa, l'avviso di aggiornamento, la richiesta di
 * cancellazione dei dati dal profilo e, dal 19/11, «Scarica i tuoi dati».
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
                Errore::pagina(404, 'Pagina non trovata.');
                exit;
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
     * «Scarica i tuoi dati» (19/11, Alessandro): un file XLSX con un foglio
     * per argomento, creato al momento e non conservato sul server. Che cosa
     * c'e' e che cosa no lo dice `DatiPersonaliModel`. Senza l'estensione zip
     * il pulsante non compare, e l'indirizzo risponde 404.
     */
    public function scaricaDati(array $params = []): void
    {
        $this->soloStudenti();

        if (!Xlsx::disponibile()) {
            Errore::pagina(404, 'Pagina non trovata.');
            exit;
        }

        Xlsx::sendFogli(
            'pistacchio-i-miei-dati-' . date('Y-m-d') . '.xlsx',
            DatiPersonaliModel::fogli((int) Auth::id())
        );
    }

    /**
     * Solo gli studenti (per ora, 19/11): lo staff ha un rapporto di lavoro,
     * non di partecipazione ai corsi, e un Amministratore che si chiude fuori
     * da solo lascerebbe la piattaforma senza nessuno.
     */
    private function soloStudenti(): void
    {
        Auth::requireLogin();

        if (!Auth::hasRole('studente')) {
            Errore::pagina(403, 'Accesso negato: questa pagina è per gli studenti.');
            exit;
        }
    }
}
