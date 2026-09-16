<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\AvatarImage;
use App\Core\Upload;
use App\Core\View;
use App\Models\GroupModel;
use App\Models\UserModel;

/**
 * Profilo dell'utente: le informazioni che compila da solo e la sua immagine.
 *
 * L'email non si cambia da qui: è la credenziale di accesso e cambiarla
 * richiederebbe una nuova verifica dell'indirizzo. Resta al pannello utenti.
 */
class ProfileController
{
    public function show(array $params = []): void
    {
        Auth::requireLogin();

        $user = UserModel::find((int) Auth::id());

        if ($user === null) {
            // Account eliminato mentre la sessione era aperta.
            Auth::logout();
            header('Location: /login');
            exit;
        }

        View::render('profile/edit', [
            'pageTitle' => 'Profilo',
            'user' => $user,
            'groups' => GroupModel::forUser((int) $user['id']),
            'maxAvatarMb' => (int) (AvatarImage::MAX_BYTES / 1024 / 1024),
        ]);
    }

    public function update(array $params = []): void
    {
        Auth::requireLogin();

        $userId = (int) Auth::id();
        $fullName = trim((string) ($_POST['full_name'] ?? ''));

        if ($fullName === '') {
            $_SESSION['flash_error'] = 'Il nome è obbligatorio.';
            $this->back();
        }

        UserModel::updateProfile(
            $userId,
            mb_substr($fullName, 0, 150),
            $this->optional('bio', 2000),
            $this->optional('phone', 40),
            $this->optional('city', 120)
        );

        // Il nome compare nella barra laterale a ogni pagina: senza questo
        // aggiornamento resterebbe quello vecchio fino al prossimo accesso.
        $_SESSION['user_name'] = $fullName;

        $_SESSION['flash_success'] = 'Profilo aggiornato.';
        $this->back();
    }

    public function updateAvatar(array $params = []): void
    {
        Auth::requireLogin();

        $userId = (int) Auth::id();
        $user = UserModel::find($userId);

        if (empty($_FILES['avatar']['name'])) {
            $_SESSION['flash_error'] = 'Scegli un\'immagine da caricare.';
            $this->back();
        }

        try {
            $path = AvatarImage::store($_FILES['avatar'], $userId);
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            $this->back();
        }

        $this->deleteFile($user['avatar_path'] ?? null);
        UserModel::updateAvatar($userId, $path);
        Auth::setAvatar($path);

        $_SESSION['flash_success'] = 'Immagine aggiornata.';
        $this->back();
    }

    public function deleteAvatar(array $params = []): void
    {
        Auth::requireLogin();

        $userId = (int) Auth::id();
        $user = UserModel::find($userId);

        $this->deleteFile($user['avatar_path'] ?? null);
        UserModel::updateAvatar($userId, null);
        Auth::setAvatar(null);

        $_SESSION['flash_success'] = 'Immagine rimossa.';
        $this->back();
    }

    /**
     * Serve l'immagine di un utente.
     *
     * Sta in /storage, fuori dal document root: la vedono le persone che hanno
     * fatto accesso alla piattaforma, non chiunque abbia l'indirizzo.
     */
    public function avatar(array $params): void
    {
        Auth::requireLogin();

        $user = UserModel::find((int) $params['id']);
        $path = $user['avatar_path'] ?? null;

        if ($path === null) {
            http_response_code(404);
            echo 'Immagine non impostata.';
            return;
        }

        $absolute = Upload::absolutePath($path);

        if (!is_file($absolute)) {
            http_response_code(404);
            echo 'Immagine non trovata.';
            return;
        }

        header('Content-Type: ' . AvatarImage::mimeFor($path));
        header('Content-Length: ' . filesize($absolute));
        header('X-Content-Type-Options: nosniff');
        // Privata: il nome del file cambia a ogni caricamento, quindi la cache
        // del browser non fa mai vedere l'immagine vecchia.
        header('Cache-Control: private, max-age=86400');
        readfile($absolute);
        exit;
    }

    // ---------------------------------------------------------------

    private function optional(string $field, int $maxLength): ?string
    {
        $value = trim((string) ($_POST[$field] ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    private function deleteFile(?string $relativePath): void
    {
        if ($relativePath !== null && $relativePath !== '') {
            @unlink(Upload::absolutePath($relativePath));
        }
    }

    private function back(): never
    {
        header('Location: /profilo');
        exit;
    }
}
