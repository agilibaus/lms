<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Core\Invites;
use App\Core\UserImport;
use App\Core\View;
use App\Models\EnrollmentModel;
use App\Models\GroupModel;
use App\Models\UserModel;

/**
 * Importazione di utenti da un file CSV.
 *
 * TRE PASSI, E IL SECONDO E' QUELLO CHE CONTA: si carica il file, si guarda
 * **l'anteprima**, si conferma. L'anteprima non e' una cortesia: con
 * duecento persone vere, la differenza fra vedere prima cosa succedera' e
 * scoprirlo dopo e' un pomeriggio di telefonate. Finche' non si preme
 * «Importa» non viene scritta una riga.
 *
 * COSA NON FA: non crea gruppi. Un gruppo nominato nel file e non esistente
 * ferma quella riga, invece di far nascere un gruppo da un refuso — un
 * errore che nessuno noterebbe finche' qualcuno non si ritrova da solo
 * dentro «Clssse A».
 *
 * DOVE STA IL CONTENUTO FRA UN PASSO E L'ALTRO: in sessione, non su disco.
 * Il file e' piccolo per definizione (mille righe al massimo), dura il
 * tempo di una conferma, e tenerlo in sessione evita di avere file di
 * anagrafiche dimenticati in una cartella temporanea del server.
 */
class UserImportController extends AdminController
{
    /** Megabyte oltre i quali il file non si guarda nemmeno. */
    private const MAX_MB = 2;

    private const SESSIONE = 'import_utenti';
    private const PAGINA = '/admin/users/importa';

    public function form(array $params = []): void
    {
        Auth::requirePermission('user.manage');

        View::render('admin/users/importa', [
            'pageTitle' => 'Importa utenti',
            'gruppi' => GroupModel::all(),
            'inAttesa' => UserModel::countPendingInvites(),
        ]);
    }

    /**
     * Passo 1 → 2: si legge il file e si mostra cosa succederebbe.
     */
    public function preview(array $params = []): void
    {
        Auth::requirePermission('user.manage');

        $file = $_FILES['file'] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->fail($this->erroreCaricamento($file['error'] ?? UPLOAD_ERR_NO_FILE), self::PAGINA);
        }

        if ((int) $file['size'] > self::MAX_MB * 1024 * 1024) {
            $this->fail('Il file supera ' . self::MAX_MB . ' MB: non è un elenco di persone.', self::PAGINA);
        }

        $contenuto = (string) file_get_contents((string) $file['tmp_name']);
        $gruppi = array_map(static fn (array $g): string => (string) $g['name'], GroupModel::all());
        $esito = UserImport::leggi($contenuto, $gruppi);

        if ($esito['errore'] !== null) {
            $this->fail($esito['errore'], self::PAGINA);
        }

        $buone = UserImport::buone($esito['righe']);

        // Chi c'e' gia' si riconosce qui e non al momento di scrivere: e'
        // quello che permette all'anteprima di dire «di questi, 12 ci sono
        // gia'» invece di dirlo dopo, a cose fatte.
        $esistenti = UserModel::existingEmails(array_column($buone, 'email'));

        foreach ($esito['righe'] as $i => $riga) {
            if ($riga['errore'] === null && in_array($riga['email'], $esistenti, true)) {
                $esito['righe'][$i]['errore'] = 'esiste già su Pistacchio: la salto';
            }
        }

        $_SESSION[self::SESSIONE] = UserImport::buone($esito['righe']);

        View::render('admin/users/importa_anteprima', [
            'pageTitle' => 'Importa utenti — anteprima',
            'righe' => $esito['righe'],
            'nuovi' => UserImport::buone($esito['righe']),
            'scartate' => UserImport::scartate($esito['righe']),
            'nomeFile' => (string) ($file['name'] ?? 'elenco.csv'),
        ]);
    }

    /**
     * Passo 2 → 3: si scrive.
     */
    public function run(array $params = []): void
    {
        Auth::requirePermission('user.manage');

        $righe = $_SESSION[self::SESSIONE] ?? null;
        unset($_SESSION[self::SESSIONE]);

        if (!is_array($righe) || $righe === []) {
            $this->fail(
                'Non c\'è niente da importare: ricarica il file. L\'anteprima scade con la sessione.',
                self::PAGINA
            );
        }

        $gruppi = [];

        foreach (GroupModel::all() as $g) {
            $gruppi[mb_strtolower((string) $g['name'])] = (int) $g['id'];
        }

        $creati = 0;
        $falliti = [];

        foreach ($righe as $riga) {
            $email = (string) $riga['email'];

            // Si ricontrolla adesso: fra l'anteprima e la conferma qualcuno
            // puo' aver creato quello stesso utente a mano, e l'indice unico
            // del database risponderebbe con un'eccezione in mezzo al giro.
            if (UserModel::findByEmail($email) !== null) {
                $falliti[] = $email . ' — creato nel frattempo da qualcun altro';
                continue;
            }

            try {
                $id = UserModel::createPendingInvite($email, (string) $riga['nome']);
            } catch (\Throwable $e) {
                error_log('[Import] ' . $e->getMessage());
                $falliti[] = $email . ' — non è stato possibile crearlo';
                continue;
            }

            $creati++;
            $gruppo = mb_strtolower((string) $riga['gruppo']);

            if ($gruppo !== '' && isset($gruppi[$gruppo])) {
                // Entrare nel gruppo iscrive anche ai corsi del gruppo: e'
                // il comportamento che i gruppi hanno sempre avuto, e qui
                // vale la pena saperlo perche' con un file si fa duecento
                // volte in un colpo.
                GroupModel::addMember($gruppi[$gruppo], $id);
                EnrollmentModel::enrollMany([$id], GroupModel::courseIds($gruppi[$gruppo]));
            }
        }

        $inAttesa = UserModel::countPendingInvites();
        $messaggio = $creati . ($creati === 1 ? ' utente importato' : ' utenti importati')
            . '. Gli inviti con la password partono a scaglioni di '
            . Invites::PER_SCAGLIONE . ': in attesa ce ne sono ' . $inAttesa . '.';

        if ($falliti !== []) {
            $this->fail(
                $messaggio . ' Non create: ' . implode('; ', array_slice($falliti, 0, 5))
                . (count($falliti) > 5 ? ' e altre ' . (count($falliti) - 5) : ''),
                '/admin/users'
            );
        }

        $this->success($messaggio, '/admin/users');
    }

    private function erroreCaricamento(int $codice): string
    {
        return match ($codice) {
            UPLOAD_ERR_NO_FILE => 'Non hai scelto nessun file.',
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'Il file è più grande di quanto il server accetti.',
            UPLOAD_ERR_PARTIAL => 'Il caricamento si è interrotto a metà: riprova.',
            default => 'Il file non è arrivato (codice ' . $codice . ').',
        };
    }
}
