<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Env;
use App\Core\Mail\MailException;
use App\Core\Mail\Mailer;
use App\Core\Url;
use App\Core\View;
use App\Models\UserModel;
use App\Models\UserTokenModel;

/**
 * Recupero autonomo della password.
 *
 * Il link vale un'ora ed è monouso. La risposta alla richiesta è sempre la
 * stessa, che l'indirizzo esista o no: altrimenti questa pagina diventerebbe
 * un modo per sapere chi ha un account.
 */
class PasswordResetController
{
    private const MIN_PASSWORD = 8;
    private const TOKEN_LIFETIME = 3600;
    private const MAX_TOKENS_PER_HOUR = 5;

    public function requestForm(array $params = []): void
    {
        View::render('auth/password_forgot', [
            'pageTitle' => 'Password dimenticata',
            'notice' => $this->takeFlash('reset_notice'),
        ], false);
    }

    public function sendLink(array $params = []): void
    {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $user = UserModel::findByEmail($email);

        if ($user !== null
            && (bool) $user['is_active']
            && ($user['email_verified_at'] ?? null) !== null
            && UserTokenModel::countRecent((int) $user['id'], UserTokenModel::PURPOSE_RESET, 3600) < self::MAX_TOKENS_PER_HOUR
        ) {
            $token = UserTokenModel::issue((int) $user['id'], UserTokenModel::PURPOSE_RESET, self::TOKEN_LIFETIME);
            $link = Url::to('/password/reimposta/' . $token);

            try {
                Mailer::send(Mailer::passwordReset($email, (string) $user['full_name'], $link));
            } catch (MailException $e) {
                error_log('[Mail] ' . $e->getMessage());

                if (Env::get('APP_DEBUG', '0') === '1') {
                    $_SESSION['reset_notice'] = 'Invio email non riuscito. Link di reimpostazione: ' . $link;
                }
            }
        }

        $_SESSION['reset_notice'] ??= 'Se l\'indirizzo indicato corrisponde a un account attivo, '
            . 'fra poco riceverai un\'email con il link per reimpostare la password. '
            . 'Il link vale un\'ora.';

        $this->redirect('/password/dimenticata');
    }

    public function resetForm(array $params): void
    {
        $token = (string) ($params['token'] ?? '');

        View::render('auth/password_reset', [
            'pageTitle' => 'Nuova password',
            'token' => $token,
            'valid' => UserTokenModel::findValid($token, UserTokenModel::PURPOSE_RESET) !== null,
            'error' => $this->takeFlash('reset_error'),
            'minPassword' => self::MIN_PASSWORD,
        ], false);
    }

    public function reset(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $row = UserTokenModel::findValid($token, UserTokenModel::PURPOSE_RESET);

        if ($row === null) {
            $_SESSION['reset_notice'] = 'Il link non è più valido: richiedine uno nuovo.';

            $this->redirect('/password/dimenticata');
        }

        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        if (strlen($password) < self::MIN_PASSWORD) {
            $this->failReset('La password deve avere almeno ' . self::MIN_PASSWORD . ' caratteri.', $token);
        }

        if ($password !== $confirm) {
            $this->failReset('Le due password non coincidono.', $token);
        }

        UserModel::updatePassword((int) $row['user_id'], $password);
        UserTokenModel::markUsed((int) $row['id']);

        // Chi reimposta la password dimostra di controllare l'indirizzo: se era
        // rimasto non verificato, ora lo è.
        UserModel::markEmailVerified((int) $row['user_id']);

        $_SESSION['login_notice'] = 'Password aggiornata: puoi accedere con la nuova password.';

        $this->redirect('/login');
    }

    // ---------------------------------------------------------------

    private function failReset(string $message, string $token): never
    {
        $_SESSION['reset_error'] = $message;

        $this->redirect('/password/reimposta/' . $token);
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
