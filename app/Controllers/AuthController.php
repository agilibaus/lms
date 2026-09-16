<?php

declare(strict_types=1);

namespace App\Controllers;

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
