<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Core\Privacy;
use App\Core\Upload;
use App\Core\View;
use App\Models\PrivacyModel;
use App\Models\UserModel;

/**
 * Impostazioni › Privacy (18/11, Alessandro): le richieste di cancellazione
 * dei dati e il testo dell'informativa, con le sue versioni. Solo
 * l'Amministratore (`settings.manage`).
 */
class PrivacyController extends AdminController
{
    private const PAGINA = '/admin/settings/privacy';

    public function index(array $params = []): void
    {
        Auth::requirePermission('settings.manage');

        View::render('admin/settings/privacy', [
            'pageTitle' => 'Privacy',
            'richieste' => PrivacyModel::richieste(),
            'inVigore' => PrivacyModel::inVigore(),
            'precedenti' => PrivacyModel::precedenti(),
            'adesso' => time(),
        ]);
    }

    /**
     * Due pulsanti, un modulo. «Pubblica una nuova versione» aggiunge una
     * versione con la data di oggi: gli studenti vedono l'avviso al prossimo
     * accesso. «Salva la correzione» riscrive la versione in vigore, per i
     * refusi, senza avvisare nessuno.
     */
    public function salva(array $params = []): void
    {
        Auth::requirePermission('settings.manage');

        $testo = Privacy::pulisci(isset($_POST['content_html']) ? (string) $_POST['content_html'] : null);

        if ($testo === null) {
            $this->fail('Il testo dell’informativa è vuoto.', self::PAGINA);
        }

        $inVigore = PrivacyModel::idInVigore();

        if (($_POST['azione'] ?? '') === 'correggi') {
            if ($inVigore === null) {
                $this->fail('Non c’è ancora una versione da correggere: pubblicala.', self::PAGINA);
            }

            PrivacyModel::correggi($inVigore, $testo);
            $this->success('Correzione salvata. Nessun avviso agli studenti.', self::PAGINA);
        }

        PrivacyModel::pubblica($testo);
        $this->success(
            $inVigore === null
                ? 'Informativa pubblicata.'
                : 'Nuova versione pubblicata. Gli studenti vedranno l’avviso al prossimo accesso.',
            self::PAGINA
        );
    }

    /**
     * «Cancella l'account» di chi l'ha chiesto. Solo da qui, solo uno
     * studente con una richiesta aperta: per tutti gli altri c'e' la scheda
     * dell'utente.
     */
    public function cancella(array $params): void
    {
        Auth::requirePermission('settings.manage');

        $user = UserModel::find((int) $params['id']);

        if ($user === null || ($user['deletion_requested_at'] ?? null) === null || $user['role'] !== 'studente') {
            $this->fail('Questa richiesta non c’è più.', self::PAGINA);
        }

        $id = (int) $user['id'];

        // Come `UserController::destroy()`: l'immagine sta su disco e la
        // cascata del database non la tocca. Il resto (iscrizioni, progressi,
        // risposte, attestati) se ne va con l'utente.
        if (!empty($user['avatar_path'])) {
            @unlink(Upload::absolutePath((string) $user['avatar_path']));
            @rmdir(Upload::absolutePath('avatars/' . $id));
        }

        UserModel::delete($id);

        $this->success('Account di ' . $user['full_name'] . ' cancellato.', self::PAGINA);
    }
}
