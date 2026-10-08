<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Mail\Mailer;
use App\Core\PasswordPolicy;
use App\Core\PersonName;
use App\Core\Url;
use App\Core\View;
use App\Models\UserModel;

/**
 * La pagina «Primo accesso» (08/10, chiesto da Elena).
 *
 * Al primo accesso lo studente sceglie come lo vedono gli altri studenti, e
 * se e' entrato con una password temporanea la cambia nella stessa pagina:
 * una pagina sola, breve, con solo quello che serve, invece di mezza pagina
 * di profilo. Poi il video di benvenuto, se c'e', e i corsi.
 *
 * «Primo accesso» vuol dire «non ha ancora scelto»: e' Auth::guardSession a
 * portare qui da ogni pagina finche' non sceglie.
 */
class FirstAccessController
{
    public function show(array $params = []): void
    {
        Auth::requireLogin();

        if (!Auth::primoAccessoPending()) {
            header('Location: /');
            exit;
        }

        $user = UserModel::find((int) Auth::id());
        $vecchio = $_SESSION['primo_accesso_old'] ?? null;
        unset($_SESSION['primo_accesso_old']);

        View::render('auth/first_access', [
            // Il titolo della pagina e' quello del riquadro: «Ciao, Marta»,
            // senza genere (come il saluto dopo l'accesso).
            'pageTitle' => 'Ciao, ' . (string) ($user['first_name'] ?? ''),
            'nome' => (string) ($user['first_name'] ?? ''),
            'cognome' => (string) ($user['last_name'] ?? ''),
            'conPassword' => Auth::mustChangePassword(),
            'scelta' => PersonName::display((string) ($vecchio ?? PersonName::PRESELEZIONATA)),
            'minPassword' => PasswordPolicy::MIN_LENGTH,
            'passwordHint' => PasswordPolicy::HINT_BREVE,
            'error' => $this->prendi('flash_error'),
        ], false);
    }

    public function save(array $params = []): void
    {
        Auth::requireLogin();

        if (!Auth::primoAccessoPending()) {
            header('Location: /');
            exit;
        }

        $userId = (int) Auth::id();
        $scelta = (string) ($_POST['name_display'] ?? '');
        $_SESSION['primo_accesso_old'] = $scelta;

        if (!array_key_exists($scelta, PersonName::SCELTE)) {
            $this->sbagliato('Scegli come ti vedono gli altri studenti.');
        }

        // Prima si controlla tutto, poi si scrive: un errore sulla password
        // non deve lasciare salvata la scelta a meta' pagina.
        $nuova = null;

        if (Auth::mustChangePassword()) {
            $attuale = (string) ($_POST['current_password'] ?? '');
            $nuova = (string) ($_POST['new_password'] ?? '');

            // La password ricevuta per email, come nel cambio password: e'
            // cio' che impedisce a chi trova una sessione aperta di prendersi
            // l'account (§4 del promemoria).
            if (!password_verify($attuale, (string) UserModel::passwordHash($userId))) {
                $this->sbagliato('La password ricevuta per email non è corretta.');
            }

            $problema = PasswordPolicy::problem($nuova);

            if ($problema !== null) {
                $this->sbagliato($problema);
            }

            if ($nuova !== (string) ($_POST['confirm_password'] ?? '')) {
                $this->sbagliato('Le due password non coincidono.');
            }

            if ($nuova === $attuale) {
                $this->sbagliato('La nuova password deve essere diversa da quella ricevuta per email.');
            }
        }

        if ($nuova !== null) {
            UserModel::updatePassword($userId, $nuova);
            Auth::refreshPasswordStamp();

            $user = UserModel::find($userId);
            Mailer::sendQuietly(Mailer::passwordChanged(
                (string) ($user['email'] ?? ''),
                (string) ($user['full_name'] ?? ''),
                Url::to('/login')
            ));
        }

        UserModel::updateNameDisplay($userId, $scelta);
        unset($_SESSION['primo_accesso_old']);

        // Da qui decide Auth::guardSession: il video di benvenuto, se c'e',
        // altrimenti i corsi.
        header('Location: /');
        exit;
    }

    private function sbagliato(string $messaggio): never
    {
        $_SESSION['flash_error'] = $messaggio;
        header('Location: ' . Auth::PRIMO_ACCESSO);
        exit;
    }

    private function prendi(string $chiave): ?string
    {
        $valore = $_SESSION[$chiave] ?? null;
        unset($_SESSION[$chiave]);

        return is_string($valore) ? $valore : null;
    }
}
