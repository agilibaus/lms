<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\PersonName;
use App\Core\TutorWelcome;
use App\Auth\Auth;
use App\Auth\GroupPeers;
use App\Core\AvatarImage;
use App\Core\Mail\Mailer;
use App\Core\PasswordPolicy;
use App\Core\Upload;
use App\Core\Url;
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
    /**
     * Quanto puo' essere lunga la presentazione: 1.000 caratteri, deciso da
     * Elena il 06/10 (prima erano 2.000). Da quando la presentazione compare
     * nella pagina del gruppo, la si legge in una scheda sopra la pagina, e
     * mille caratteri sono gia' una schermata intera di telefono.
     *
     * Il limite e' doppio come per le risposte aperte dei questionari:
     * `maxlength` nel campo e il taglio qui, perche' un `maxlength` si toglie
     * dagli strumenti per sviluppatori.
     */
    public const MAX_BIO_CHARS = 1000;

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

        // Nome e cognome separati (07/10).
        try {
            [$firstName, $lastName] = PersonName::clean(
                (string) ($_POST['first_name'] ?? ''),
                (string) ($_POST['last_name'] ?? '')
            );
        } catch (\InvalidArgumentException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            $this->back();
        }

        $fullName = $firstName . ' ' . $lastName;

        // L'email per gli studenti (07/10): solo per chi carica il proprio
        // benvenuto, cioe' i tutor. Compare nel benvenuto in cima ai corsi.
        // Si controlla prima di scrivere qualunque cosa: un indirizzo
        // sbagliato non deve salvare a meta' il profilo.
        $contatto = false;

        if (Auth::can('course.welcome_own')) {
            try {
                $contatto = TutorWelcome::contactEmail((string) ($_POST['contact_email'] ?? ''));
            } catch (\InvalidArgumentException $e) {
                $_SESSION['flash_error'] = $e->getMessage();
                $this->back();
            }
        }

        UserModel::updateProfile(
            $userId,
            $firstName,
            $lastName,
            $this->optional('bio', self::MAX_BIO_CHARS),
            $this->optional('phone', 40),
            $this->optional('city', 120)
        );

        if ($contatto !== false) {
            UserModel::updateContactEmail($userId, $contatto);
        }


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

        // Il tutor la sostituisce, non la toglie (09/10): il comando non gli
        // viene offerto, e una richiesta scritta a mano riceve 403, come la
        // scelta di come compaiono gli studenti chiesta da chi non lo e'.
        if (AvatarImage::obbligatoria((string) ($user['role'] ?? ''))) {
            http_response_code(403);
            echo 'Un tutor ha sempre una foto del profilo: puoi sostituirla, non toglierla.';
            return;
        }

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

        /*
         * Chi puo' vedere la foto lo decide `GroupPeers`: la persona stessa,
         * l'amministratore, e chi sta in un gruppo con lei (il tutor del
         * gruppo compreso). Prima bastava aver fatto accesso, e scrivendo un
         * numero a caso nell'indirizzo si vedeva la foto di chiunque.
         *
         * Il rifiuto risponde come una foto che non c'e', con la stessa
         * frase: un 403 direbbe che quella persona una foto ce l'ha.
         */
        if (!GroupPeers::canSeeAvatar((int) Auth::id(), (int) $params['id'])) {
            http_response_code(404);
            echo 'Immagine non impostata.';
            return;
        }

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

    // ---------------------------------------------------------------
    /**
     * Come lo studente compare agli altri studenti (07/10): un riquadro suo
     * nel profilo, con il suo salvataggio. Solo per gli studenti: tutor e
     * admin compaiono sempre con nome e cognome.
     */
    public function updateNameDisplay(array $params = []): void
    {
        Auth::requireLogin();

        if (!Auth::hasRole('studente')) {
            http_response_code(403);
            echo 'Questa scelta riguarda solo gli studenti.';
            return;
        }

        UserModel::updateNameDisplay((int) Auth::id(), (string) ($_POST['name_display'] ?? PersonName::FULL));
        $_SESSION['flash_success'] = 'Scelta salvata.';
        $this->back();
    }

    // Cambio password
    // ---------------------------------------------------------------

    /**
     * Pagina a sé e non un riquadro dentro il profilo, perché è anche
     * l'unica pagina raggiungibile da chi ha una password temporanea: in quel
     * caso va mostrata da sola, senza la barra laterale, i cui collegamenti
     * riporterebbero comunque qui.
     */
    public function passwordForm(array $params = []): void
    {
        Auth::requireLogin();

        $obbligato = Auth::mustChangePassword();

        View::render('profile/password', [
            'pageTitle' => 'Cambia password',
            'obbligato' => $obbligato,
            'minPassword' => PasswordPolicy::MIN_LENGTH,
            'passwordHint' => PasswordPolicy::HINT,
            'error' => $this->takeFlash('flash_error'),
        ], !$obbligato);
    }

    public function changePassword(array $params = []): void
    {
        Auth::requireLogin();

        $user = UserModel::find((int) Auth::id());

        if ($user === null) {
            Auth::logout();
            header('Location: /login');
            exit;
        }

        $attuale = (string) ($_POST['current_password'] ?? '');
        $nuova = (string) ($_POST['new_password'] ?? '');
        $conferma = (string) ($_POST['confirm_password'] ?? '');

        // La password attuale è ciò che impedisce a chi trovi una sessione
        // aperta di prendersi l'account cambiandola.
        if (!password_verify($attuale, (string) UserModel::passwordHash((int) $user['id']))) {
            $this->failPassword('La password attuale non è corretta.');
        }

        $problema = PasswordPolicy::problem($nuova);

        if ($problema !== null) {
            $this->failPassword($problema);
        }

        if ($nuova !== $conferma) {
            $this->failPassword('Le due password non coincidono.');
        }

        if ($nuova === $attuale) {
            $this->failPassword('La nuova password deve essere diversa da quella attuale.');
        }

        UserModel::updatePassword((int) $user['id'], $nuova);

        // Il browser da cui si sta cambiando non deve cadere insieme agli
        // altri: la sua copia dell'istante va riallineata subito.
        Auth::refreshPasswordStamp();

        // L'avviso non deve poter impedire il cambio: se la posta non parte,
        // la password è cambiata lo stesso e l'errore finisce nel log.
        Mailer::sendQuietly(Mailer::passwordChanged(
            (string) $user['email'],
            (string) $user['full_name'],
            Url::to('/login')
        ));

        $_SESSION['flash_success'] = 'Password aggiornata. Le altre sessioni aperte con la password precedente sono state chiuse.';
        header('Location: /profilo');
        exit;
    }

    private function failPassword(string $message): never
    {
        $_SESSION['flash_error'] = $message;
        header('Location: ' . Auth::PASSWORD_PAGE);
        exit;
    }

    private function takeFlash(string $key): ?string
    {
        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        return is_string($value) ? $value : null;
    }

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
