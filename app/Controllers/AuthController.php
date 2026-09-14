<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
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
        unset($_SESSION['login_error']);

        View::render('auth/login', [
            'pageTitle' => 'Accedi',
            'error' => $error,
        ], withShell: false);
    }

    public function login(array $params = []): void
    {
        $email = trim($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '' || !Auth::attempt($email, $password)) {
            $_SESSION['login_error'] = 'Email o password non corretti.';
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
