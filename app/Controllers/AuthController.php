<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserModel;
use App\Auth\Auth;
use App\Core\View;

class AuthController
{
    public function showLogin(array $params = []): void
    {
        if (Auth::check()) {
            header('Location: /');
            exit;
        }

        $error = $_SESSION['login_error'] ?? null;
        $notice = $_SESSION['login_notice'] ?? null;
        $unverified = ($_SESSION['login_unverified'] ?? false) === true;
        unset($_SESSION['login_error'], $_SESSION['login_notice'], $_SESSION['login_unverified']);

        // Chi arriva qui perche' la sua sessione e' stata chiusa non puo'
        // essere avvisato con un messaggio in sessione: la sessione non c'e'
        // piu'. Il motivo viaggia quindi nell'indirizzo, e qui diventa una
        // frase scelta fra queste, mai il testo ricevuto: cosi' nessuno puo'
        // far scrivere quello che vuole nella pagina di accesso.
        $notice ??= match ($_GET['motivo'] ?? null) {
            'password' => 'La password di questo account è stata cambiata. Entra con quella nuova.',
            'sessione' => 'La sessione non è più valida. Entra di nuovo.',
            default => null,
        };

        View::render('auth/login', [
            'pageTitle' => 'Accedi',
            'error' => $error,
            'notice' => $notice,
            'unverified' => $unverified,
        ], withShell: false);
    }

    public function login(array $params = []): void
    {
        $email = trim($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '' || !Auth::attempt($email, $password)) {
            // Il motivo si puo' dettagliare solo quando la password era giusta:
            // altrimenti si direbbe a un estraneo quali indirizzi sono registrati.
            [$message, $unverified] = match (Auth::failureReason()) {
                Auth::FAILURE_UNVERIFIED => [
                    'Devi confermare il tuo indirizzo email prima di accedere.',
                    true,
                ],
                Auth::FAILURE_INACTIVE => [
                    'Questo account è disattivato: contatta chi gestisce la piattaforma.',
                    false,
                ],
                default => ['Email o password non corretti.', false],
            };

            $_SESSION['login_error'] = $message;
            $_SESSION['login_unverified'] = $unverified;

            header('Location: /login');
            exit;
        }

        // Il saluto «Che bello rivederti, Marta! 🌸» (08/10, chiesto da Elena):
        // solo agli studenti, a ogni accesso tranne il primo in assoluto, che
        // ha il video di benvenuto, e tranne quando c'e' una password
        // temporanea da cambiare. Si annota qui il nome; lo mostra, una volta
        // sola, la prima pagina che si apre. Una frase senza genere, scelta
        // di Elena: Pistacchio non sa se dire «bentornata» o «bentornato».
        if (Auth::hasRole('studente') && !Auth::welcomePending() && !Auth::mustChangePassword()) {
            $_SESSION['saluto'] = (string) (UserModel::find((int) Auth::id())['first_name'] ?? '');
        }

        header('Location: /');
        exit;
    }

    public function logout(array $params = []): void
    {
        Auth::logout();
        header('Location: /login');
        exit;
    }
}
