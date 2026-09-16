<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Env;
use App\Core\Mail\MailException;
use App\Core\Mail\Mailer;
use App\Core\Url;
use App\Core\View;
use App\Models\UserModel;
use App\Models\UserTokenModel;

/**
 * Registrazione autonoma degli studenti, con conferma dell'indirizzo email.
 *
 * L'account nasce attivo ma non verificato: finché l'indirizzo non è confermato
 * il login viene rifiutato. Chi si registra da qui è sempre uno `studente`; gli
 * altri ruoli restano appannaggio del pannello di amministrazione.
 */
class RegistrationController
{
    private const MIN_PASSWORD = 8;
    private const TOKEN_LIFETIME = 86400;          // 24 ore

    /** Non più di 5 link di verifica all'ora per lo stesso account. */
    private const MAX_TOKENS_PER_HOUR = 5;

    public function showForm(array $params = []): void
    {
        if (Auth::check()) {
            $this->redirect('/');
        }

        View::render('auth/register', [
            'pageTitle' => 'Registrati',
            'error' => $this->takeFlash('register_error'),
            'old' => $_SESSION['register_old'] ?? [],
        ], false);

        unset($_SESSION['register_old']);
    }

    public function register(array $params = []): void
    {
        if (Auth::check()) {
            $this->redirect('/');
        }

        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        $_SESSION['register_old'] = ['full_name' => $fullName, 'email' => $email];

        if ($fullName === '' || $email === '') {
            $this->fail('Nome ed email sono obbligatori.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fail('Indirizzo email non valido.');
        }

        if (strlen($password) < self::MIN_PASSWORD) {
            $this->fail('La password deve avere almeno ' . self::MIN_PASSWORD . ' caratteri.');
        }

        if ($password !== $confirm) {
            $this->fail('Le due password non coincidono.');
        }

        // Se l'indirizzo è già registrato non lo diciamo: la pagina di esito è
        // identica in entrambi i casi, così non si può usare la registrazione
        // per scoprire chi è iscritto alla piattaforma.
        $existing = UserModel::findByEmail($email);

        if ($existing === null) {
            $userId = UserModel::create($email, $password, $fullName, 'studente', null, true, false);
            $this->sendVerification($userId, $email, $fullName);
        } elseif (($existing['email_verified_at'] ?? null) === null) {
            // Registrazione ripetuta di un account mai confermato: nuovo link.
            $this->sendVerification((int) $existing['id'], $email, (string) $existing['full_name']);
        }

        unset($_SESSION['register_old']);
        $_SESSION['verification_email'] = $email;

        $this->redirect('/register/verifica-inviata');
    }

    /**
     * Pagina di cortesia dopo la registrazione e dopo un rinvio.
     */
    public function pending(array $params = []): void
    {
        View::render('auth/verify_pending', [
            'pageTitle' => 'Controlla la posta',
            'email' => $_SESSION['verification_email'] ?? '',
            'usesLogTransport' => Mailer::isLogTransport(),
            'notice' => $this->takeFlash('verification_notice'),
        ], false);
    }

    /**
     * Conferma dell'indirizzo tramite il link ricevuto per email.
     */
    public function verify(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $row = UserTokenModel::findValid($token, UserTokenModel::PURPOSE_VERIFICATION);

        if ($row === null) {
            View::render('auth/verify_result', [
                'pageTitle' => 'Link non valido',
                'success' => false,
            ], false);

            return;
        }

        UserTokenModel::markUsed((int) $row['id']);
        UserModel::markEmailVerified((int) $row['user_id']);

        View::render('auth/verify_result', [
            'pageTitle' => 'Indirizzo confermato',
            'success' => true,
        ], false);
    }

    public function resendForm(array $params = []): void
    {
        View::render('auth/verify_resend', [
            'pageTitle' => 'Rinvia il link di conferma',
            'error' => $this->takeFlash('register_error'),
        ], false);
    }

    public function resend(array $params = []): void
    {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $user = UserModel::findByEmail($email);

        // Come sopra: l'esito non cambia a seconda che l'indirizzo esista.
        if ($user !== null && ($user['email_verified_at'] ?? null) === null) {
            if (UserTokenModel::countRecent((int) $user['id'], UserTokenModel::PURPOSE_VERIFICATION, 3600) >= self::MAX_TOKENS_PER_HOUR) {
                $_SESSION['verification_notice'] = 'Hai già richiesto il link più volte: controlla la posta, anche nello spam, e riprova fra un\'ora.';
            } else {
                $this->sendVerification((int) $user['id'], $email, (string) $user['full_name']);
            }
        }

        $_SESSION['verification_email'] = $email;

        $this->redirect('/register/verifica-inviata');
    }

    // ---------------------------------------------------------------

    private function sendVerification(int $userId, string $email, string $fullName): void
    {
        $token = UserTokenModel::issue($userId, UserTokenModel::PURPOSE_VERIFICATION, self::TOKEN_LIFETIME);
        $link = Url::to('/verifica-email/' . $token);

        try {
            Mailer::send(Mailer::verification($email, $fullName, $link));
        } catch (MailException $e) {
            error_log('[Mail] ' . $e->getMessage());

            // Senza email l'account resterebbe inaccessibile: meglio dirlo.
            $_SESSION['verification_notice'] = Env::get('APP_DEBUG', '0') === '1'
                ? 'Invio email non riuscito (' . $e->getMessage() . '). Link di verifica: ' . $link
                : 'Non siamo riusciti a inviare l\'email di conferma. Riprova più tardi o contatta chi gestisce la piattaforma.';
        }
    }

    private function fail(string $message): never
    {
        $_SESSION['register_error'] = $message;

        $this->redirect('/register');
    }

    private function takeFlash(string $key): ?string
    {
        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        return $value;
    }

    private function redirect(string $location): never
    {
        header('Location: ' . $location);
        exit;
    }
}
