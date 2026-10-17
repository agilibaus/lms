<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\BachecaScope;
use App\Core\Bacheca;
use App\Core\CampoContato;
use App\Core\Mail\Mailer;
use App\Core\PersonName;
use App\Core\Upload;
use App\Core\Url;
use App\Core\View;
use App\Models\BoardModel;
use App\Models\CourseModel;
use App\Models\GroupModel;
use App\Models\UserModel;

/**
 * La Bacheca (17/10, Alessandro): le risorse condivise di un corso. Le
 * regole stanno in `Bacheca` (i campi, l'ordine, i link) e in
 * `BachecaScope` (chi vede, chi pubblica, chi modera); qui le pagine e le
 * azioni.
 *
 * Le pagine: la griglia delle categorie del corso, la pagina di una
 * categoria (con «Le mie risorse»), «Condividi una risorsa» per lo
 * studente, «Nuova risorsa» e le proposte da valutare per lo staff, la
 * modifica di una risorsa.
 */
class BoardController
{
    // -----------------------------------------------------------------
    // Pagine
    // -----------------------------------------------------------------

    public function index(array $params = []): void
    {
        Auth::requireLogin();

        $corsi = BachecaScope::corsi();
        $corso = self::corsoScelto($corsi);
        $dati = ['pageTitle' => 'Bacheca', 'corsi' => $corsi, 'corso' => $corso];

        if ($corso !== null) {
            $courseId = (int) $corso['id'];
            $partecipa = BachecaScope::partecipa($courseId);
            $dati += [
                'categorie' => BoardModel::categorie($courseId),
                'partecipa' => $partecipa,
                'tenute' => $partecipa ? count(BoardModel::tenute((int) Auth::id(), $courseId)) : 0,
                'daValutare' => count(BoardModel::inAttesa($courseId, BachecaScope::moderati($courseId))),
                'puoPubblicare' => BachecaScope::puoPubblicare($courseId),
                'gestisceCategorie' => Auth::can('board.manage'),
            ];
        }

        View::render('bacheca/index', $dati);
    }

    /** La pagina di una categoria, con le schede «Tutte» e «Le mie risorse». */
    public function category(array $params): void
    {
        Auth::requireLogin();

        $categoria = BoardModel::categoria((int) $params['id']);

        if ($categoria === null) {
            $this->ferma(404, 'Categoria non trovata.');
        }

        $courseId = (int) $categoria['course_id'];
        $this->leggibile($courseId);

        $tutte = BoardModel::pubblicate($courseId, (int) $categoria['id']);
        $tenute = BoardModel::tenute((int) Auth::id(), $courseId);
        $mie = ($_GET['mie'] ?? '') === '1';
        $mieQui = array_values(array_filter($tutte, static fn (array $r): bool => in_array((int) $r['id'], $tenute, true)));

        View::render('bacheca/categoria', [
            'pageTitle' => (string) $categoria['name'],
            'corso' => CourseModel::find($courseId),
            'categoria' => $categoria,
            'mie' => $mie,
            'conteggi' => ['tutte' => count($tutte), 'mie' => count($mieQui)],
            'risorse' => self::prepara($mie ? $mieQui : $tutte, $courseId, $tenute),
            'qui' => '/bacheca/categorie/' . (int) $categoria['id'] . ($mie ? '?mie=1' : ''),
        ]);
    }

    /** «Le mie risorse» di tutto il corso: quelle che uno si tiene. */
    public function mine(array $params = []): void
    {
        Auth::requireLogin();

        $corso = self::corsoScelto(BachecaScope::corsi());

        if ($corso === null) {
            $this->ferma(403, 'Non hai una bacheca da aprire.');
        }

        $courseId = (int) $corso['id'];
        $tenute = BoardModel::tenute((int) Auth::id(), $courseId);
        $mie = array_values(array_filter(
            BoardModel::pubblicate($courseId),
            static fn (array $r): bool => in_array((int) $r['id'], $tenute, true)
        ));

        View::render('bacheca/categoria', [
            'pageTitle' => 'Le mie risorse',
            'corso' => $corso,
            'categoria' => null,
            'mie' => true,
            'conteggi' => ['tutte' => 0, 'mie' => count($mie)],
            'risorse' => self::prepara($mie, $courseId, $tenute),
            'qui' => '/bacheca/mie?corso=' . $courseId,
        ]);
    }

    /** «Condividi una risorsa», per lo studente: il modulo e le sue proposte. */
    public function shareForm(array $params = []): void
    {
        Auth::requireLogin();

        $corso = self::corsoScelto(BachecaScope::corsi());

        if ($corso === null || !BachecaScope::partecipa((int) $corso['id'])) {
            $this->ferma(403, 'Propongono una risorsa gli studenti iscritti al corso.');
        }

        $courseId = (int) $corso['id'];

        View::render('bacheca/form', [
            'pageTitle' => 'Condividi una risorsa',
            'corso' => $corso,
            'categorie' => BoardModel::categorie($courseId),
            'risorsa' => self::vecchi(),
            'azione' => '/bacheca/corsi/' . $courseId . '/condividi',
            'staff' => false,
            'proposte' => BoardModel::proposteDi((int) Auth::id(), $courseId),
        ]);
    }

    /** «Nuova risorsa», per chi pubblica. */
    public function createForm(array $params = []): void
    {
        Auth::requireLogin();

        $corso = self::corsoScelto(BachecaScope::corsi());

        if ($corso === null || !BachecaScope::puoPubblicare((int) $corso['id'])) {
            $this->ferma(403, 'Non puoi pubblicare nella bacheca di questo corso.');
        }

        $courseId = (int) $corso['id'];
        $vecchi = self::vecchi();
        $vecchi['category_id'] ??= (int) ($_GET['categoria'] ?? 0);

        View::render('bacheca/form', [
            'pageTitle' => 'Nuova risorsa',
            'corso' => $corso,
            'categorie' => BoardModel::categorie($courseId),
            'risorsa' => $vecchi,
            'azione' => '/bacheca/corsi/' . $courseId . '/nuova',
            'staff' => true,
        ]);
    }

    /** Le proposte da valutare: per il tutor quelle dei suoi studenti, per l'admin tutte. */
    public function proposals(array $params = []): void
    {
        Auth::requireLogin();

        $corso = self::corsoScelto(BachecaScope::corsi());
        $moderati = $corso === null ? [] : BachecaScope::moderati((int) $corso['id']);

        // Il tutor che pubblica nel corso apre la pagina anche se in quel
        // corso non ha studenti: la trova vuota, non chiusa.
        if ($corso === null || ($moderati === [] && !BachecaScope::puoPubblicare((int) $corso['id']))) {
            $this->ferma(403, 'Le proposte le valutano il tutor del gruppo e l\'admin.');
        }

        $courseId = (int) $corso['id'];
        $proposte = array_map(static function (array $r) use ($courseId): array {
            $gruppo = $r['author_id'] === null ? null : GroupModel::delloStudenteNelCorso((int) $r['author_id'], $courseId);

            return $r + [
                'autore' => (string) ($r['full_name'] ?? 'uno studente'),
                'gruppo' => $gruppo === null ? null : (string) (GroupModel::find($gruppo)['name'] ?? ''),
            ];
        }, BoardModel::inAttesa($courseId, $moderati));

        View::render('bacheca/proposte', [
            'pageTitle' => 'Proposte da valutare',
            'corso' => $corso,
            'categorie' => BoardModel::categorie($courseId),
            'proposte' => $proposte,
        ]);
    }

    public function editForm(array $params): void
    {
        $risorsa = $this->daGestire((int) $params['id']);
        $courseId = (int) $risorsa['course_id'];

        View::render('bacheca/form', [
            'pageTitle' => $risorsa['status'] === Bacheca::PENDING ? 'Correggi la proposta' : 'Modifica la risorsa',
            'corso' => CourseModel::find($courseId),
            'categorie' => BoardModel::categorie($courseId),
            'risorsa' => self::vecchi() + $risorsa,
            'azione' => '/bacheca/risorse/' . (int) $risorsa['id'],
            'staff' => true,
        ]);
    }

    // -----------------------------------------------------------------
    // Azioni
    // -----------------------------------------------------------------

    /** Lo studente propone: la risorsa aspetta il tutor del suo gruppo. */
    public function share(array $params): void
    {
        Auth::requireLogin();

        $courseId = (int) $params['id'];
        $corso = CourseModel::find($courseId);

        if ($corso === null || !BachecaScope::partecipa($courseId)) {
            $this->ferma(403, 'Propongono una risorsa gli studenti iscritti al corso.');
        }

        $dietro = '/bacheca/condividi?corso=' . $courseId;
        $dati = $this->campi($courseId, $dietro);
        $userId = (int) Auth::id();
        BoardModel::crea($courseId, $userId, $dati, Bacheca::PENDING);
        unset($_SESSION['bacheca_old']);

        $studente = (string) (UserModel::find($userId)['full_name'] ?? '');
        $link = Url::to('/bacheca/proposte?corso=' . $courseId);

        foreach (BoardModel::destinatari($userId, $courseId) as $d) {
            Mailer::sendQuietly(Mailer::boardProposal($d['email'], $d['full_name'], $studente, (string) $corso['title'], $dati['title'], $link));
        }

        $this->torna($dietro, 'Proposta inviata: la trovi qui sotto, in «Le tue proposte». Il tutor la leggerà e la pubblicherà.', true);
    }

    /** Lo staff pubblica direttamente, anche con un file. */
    public function store(array $params): void
    {
        Auth::requireLogin();

        $courseId = (int) $params['id'];

        if (CourseModel::find($courseId) === null || !BachecaScope::puoPubblicare($courseId)) {
            $this->ferma(403, 'Non puoi pubblicare nella bacheca di questo corso.');
        }

        $dietro = '/bacheca/nuova?corso=' . $courseId;
        $dati = $this->campi($courseId, $dietro);
        $file = $this->file($dati['kind'], $courseId, $dietro, false);
        $id = BoardModel::crea($courseId, (int) Auth::id(), $dati, Bacheca::PUBLISHED);

        if ($file !== null) {
            BoardModel::impostaFile($id, $file['stored_path'], $file['original_name'], $file['size']);
        }

        BoardModel::evidenza($id, ($_POST['featured'] ?? '') === '1');
        unset($_SESSION['bacheca_old']);
        $this->torna('/bacheca/categorie/' . (int) $dati['category_id'], 'Risorsa pubblicata.', true);
    }

    /**
     * Corregge una risorsa; se e' una proposta in attesa e si preme
     * «Salva e pubblica», la pubblica anche.
     */
    public function update(array $params): void
    {
        $risorsa = $this->daGestire((int) $params['id']);
        $id = (int) $risorsa['id'];
        $courseId = (int) $risorsa['course_id'];
        $dietro = '/bacheca/risorse/' . $id . '/modifica';
        $dati = $this->campi($courseId, $dietro);
        $togliFile = ($_POST['remove_file'] ?? '') === '1';
        $file = $this->file($dati['kind'], $courseId, $dietro, !empty($risorsa['file_path']) && !$togliFile);

        if ($file !== null || $togliFile) {
            self::cancellaFile($risorsa);
            BoardModel::impostaFile($id, $file['stored_path'] ?? null, $file['original_name'] ?? null, $file['size'] ?? null);
        }

        BoardModel::aggiorna($id, $dati);
        unset($_SESSION['bacheca_old']);

        if ($risorsa['status'] === Bacheca::PENDING) {
            if (($_POST['publish'] ?? '') === '1') {
                BoardModel::pubblica($id, (int) $dati['category_id'], (int) Auth::id());
                $this->torna('/bacheca/proposte?corso=' . $courseId, 'Proposta corretta e pubblicata.', true);
            }

            $this->torna('/bacheca/proposte?corso=' . $courseId, 'Proposta corretta: è ancora in attesa.', true);
        }

        if ($risorsa['status'] === Bacheca::PUBLISHED) {
            BoardModel::evidenza($id, ($_POST['featured'] ?? '') === '1');
        }

        $this->torna('/bacheca/categorie/' . (int) $dati['category_id'], 'Modifica salvata.', true);
    }

    /** Pubblica una proposta nella categoria scelta. */
    public function publish(array $params): void
    {
        $risorsa = $this->daGestire((int) $params['id']);
        $courseId = (int) $risorsa['course_id'];
        $categoria = (int) ($_POST['category_id'] ?? $risorsa['category_id']);
        $dietro = '/bacheca/proposte?corso=' . $courseId;

        if (!in_array($categoria, self::idCategorie($courseId), true)) {
            $this->torna($dietro, 'Scegli una categoria di questa bacheca.', false);
        }

        $fatto = $risorsa['status'] === Bacheca::PENDING && BoardModel::pubblica((int) $risorsa['id'], $categoria, (int) Auth::id());
        $this->torna($dietro, $fatto ? 'Proposta pubblicata.' : 'Questa proposta era già stata valutata.', $fatto);
    }

    /** «Non pubblicare»: la proposta resta, lo studente la vede «Non pubblicata». */
    public function discard(array $params): void
    {
        $risorsa = $this->daGestire((int) $params['id']);
        $fatto = $risorsa['status'] === Bacheca::PENDING && BoardModel::scarta((int) $risorsa['id'], (int) Auth::id());
        $this->torna('/bacheca/proposte?corso=' . (int) $risorsa['course_id'],
            $fatto ? 'Proposta non pubblicata.' : 'Questa proposta era già stata valutata.', $fatto);
    }

    /** Toglie una pubblicata dalla bacheca, senza cancellarla. */
    public function withdraw(array $params): void
    {
        $risorsa = $this->daGestire((int) $params['id']);
        $fatto = BoardModel::togli((int) $risorsa['id'], (int) Auth::id());
        $this->torna('/bacheca/categorie/' . (int) $risorsa['category_id'],
            $fatto ? 'Risorsa tolta dalla bacheca.' : 'Questa risorsa non è più pubblicata.', $fatto);
    }

    public function feature(array $params): void
    {
        $risorsa = $this->daGestire((int) $params['id']);
        $si = ($_POST['si'] ?? '') === '1';
        BoardModel::evidenza((int) $risorsa['id'], $si);
        $this->torna(Bacheca::torna((string) ($_POST['torna'] ?? ''), '/bacheca/categorie/' . (int) $risorsa['category_id']),
            $si ? 'Risorsa messa in evidenza.' : 'Risorsa tolta dall’evidenza.', true);
    }

    /** «Questa me la tengo», e il contrario. */
    public function save(array $params): void
    {
        $risorsa = $this->pubblicataLeggibile((int) $params['id']);
        $tenuta = BoardModel::tieni((int) Auth::id(), (int) $risorsa['id']);
        $this->torna(Bacheca::torna((string) ($_POST['torna'] ?? ''), '/bacheca/categorie/' . (int) $risorsa['category_id']),
            $tenuta ? 'Te la tieni: la trovi in «Le mie risorse».' : 'Tolta da «Le mie risorse».', true,
            '#risorsa-' . (int) $risorsa['id']);
    }

    /** «Come l'ho utilizzata»: lo scrive o lo corregge lo studente iscritto. */
    public function story(array $params): void
    {
        $risorsa = $this->pubblicataLeggibile((int) $params['id']);
        $dietro = Bacheca::torna((string) ($_POST['torna'] ?? ''), '/bacheca/categorie/' . (int) $risorsa['category_id']);

        if (!BachecaScope::partecipa((int) $risorsa['course_id'])) {
            $this->ferma(403, 'Raccontano come l\'hanno utilizzata gli studenti iscritti al corso.');
        }

        $testo = CampoContato::taglia((string) ($_POST['body'] ?? ''), Bacheca::MAX_USO + 1);

        if ($testo === '' || mb_strlen($testo) > Bacheca::MAX_USO) {
            $this->torna($dietro, $testo === '' ? 'Scrivi come l’hai utilizzata prima di inviare.'
                : 'Il racconto supera i ' . Bacheca::MAX_USO . ' caratteri.', false, '#risorsa-' . (int) $risorsa['id']);
        }

        BoardModel::salvaUso((int) $risorsa['id'], (int) Auth::id(), $testo);
        $this->torna($dietro, 'Grazie: il tuo racconto è pubblicato sotto la risorsa.', true, '#risorsa-' . (int) $risorsa['id']);
    }

    /** Nasconde un racconto, o lo rimette: chi modera lo studente che l'ha scritto. */
    public function moderateUse(array $params): void
    {
        Auth::requireLogin();

        $uso = BoardModel::uso((int) $params['id']);
        $risorsa = $uso === null ? null : BoardModel::risorsa((int) $uso['resource_id']);

        if ($uso === null || $risorsa === null || !BachecaScope::puoModerare((int) $uso['user_id'], (int) $risorsa['course_id'])) {
            $this->ferma(403, 'Non puoi moderare questo racconto.');
        }

        $nascondi = ($_POST['azione'] ?? '') === 'nascondi';
        BoardModel::nascondiUso((int) $uso['id'], $nascondi ? (int) Auth::id() : null);
        $this->torna(Bacheca::torna((string) ($_POST['torna'] ?? ''), '/bacheca/categorie/' . (int) $risorsa['category_id']),
            $nascondi ? 'Racconto nascosto: lo vede solo chi l’ha scritto.' : 'Racconto di nuovo visibile.', true,
            '#risorsa-' . (int) $risorsa['id']);
    }

    /**
     * Il file di una risorsa, come i materiali delle lezioni: sempre come
     * allegato, mai aperto nel browser. Una pubblicata a chi legge la
     * bacheca; una proposta o una tolta solo a chi la gestisce.
     */
    public function download(array $params): void
    {
        Auth::requireLogin();

        $risorsa = BoardModel::risorsa((int) $params['id']);

        if ($risorsa === null || empty($risorsa['file_path'])) {
            $this->ferma(404, 'File non trovato.');
        }

        $pubblicata = $risorsa['status'] === Bacheca::PUBLISHED;

        if (!($pubblicata ? BachecaScope::puoLeggere((int) $risorsa['course_id']) : BachecaScope::gestisce($risorsa))) {
            $this->ferma(403, 'Non puoi scaricare questo file.');
        }

        $assoluto = Upload::absolutePath((string) $risorsa['file_path']);

        if (!is_file($assoluto)) {
            $this->ferma(404, 'File non trovato sul server.');
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', basename((string) $risorsa['file_name'])) . '"');
        header('Content-Length: ' . filesize($assoluto));
        header('X-Content-Type-Options: nosniff');
        readfile($assoluto);
        exit;
    }

    // -----------------------------------------------------------------

    /**
     * Le risorse pronte per la vista: chi l'ha condivisa (con il nome come lo
     * vede chi guarda), se chi guarda la gestisce o la tiene, i racconti che
     * puo' vedere e il suo.
     *
     * @param list<array<string, mixed>> $risorse
     * @param list<int> $tenute
     * @return list<array<string, mixed>>
     */
    private static function prepara(array $risorse, int $courseId, array $tenute): array
    {
        $chi = (int) Auth::id();
        $moderati = BachecaScope::moderati($courseId);
        $modera = static fn (?int $id): bool => $moderati === null || ($id !== null && in_array($id, $moderati, true));
        $usi = BoardModel::usi(array_map(static fn (array $r): int => (int) $r['id'], $risorse));
        $partecipa = BachecaScope::partecipa($courseId);

        return array_map(static function (array $r) use ($chi, $modera, $usi, $tenute, $partecipa): array {
            $autore = $r['author_id'] === null ? null : (int) $r['author_id'];
            $nome = $autore === null ? '' : PersonName::shown(self::persona($r, $autore), $chi, $modera($autore));
            $suoi = [];
            $mio = null;

            foreach ($usi[(int) $r['id']] ?? [] as $u) {
                $diChi = (int) $u['user_id'];
                $nascosto = $u['hidden_at'] !== null;

                if ($diChi === $chi) {
                    $mio = $u;
                }

                if ($nascosto && $diChi !== $chi && !$modera($diChi)) {
                    continue;
                }

                $suoi[] = $u + [
                    'autore' => PersonName::shown(self::persona($u, $diChi), $chi, $modera($diChi)),
                    'nascosto' => $nascosto,
                    'modera' => $modera($diChi),
                ];
            }

            return $r + [
                'chi' => Bacheca::attribuzione($autore === null ? null : (string) $r['author_role'], $nome),
                'quando' => date('d/m/Y', strtotime((string) ($r['published_at'] ?? $r['created_at']))),
                'gestisce' => BachecaScope::gestisce($r),
                'tenuta' => in_array((int) $r['id'], $tenute, true),
                'usi' => $suoi,
                'mio_uso' => $mio,
                'racconta' => $partecipa,
            ];
        }, $risorse);
    }

    /** @return array<string, mixed> */
    private static function persona(array $riga, int $id): array
    {
        return ['id' => $id, 'first_name' => (string) $riga['first_name'], 'last_name' => (string) $riga['last_name'],
            'full_name' => (string) $riga['full_name'], 'name_display' => (string) ($riga['name_display'] ?? '')];
    }

    /**
     * Il corso scelto con `?corso=`, fra quelli che si possono leggere; se
     * non c'e' o non e' fra questi, il primo.
     *
     * @param list<array<string, mixed>> $corsi
     * @return array<string, mixed>|null
     */
    private static function corsoScelto(array $corsi): ?array
    {
        $chiesto = (int) ($_GET['corso'] ?? 0);

        foreach ($corsi as $c) {
            if ((int) $c['id'] === $chiesto) {
                return $c;
            }
        }

        return $corsi[0] ?? null;
    }

    /** @return list<int> */
    private static function idCategorie(int $courseId): array
    {
        return array_map(static fn (array $c): int => (int) $c['id'], BoardModel::categorie($courseId));
    }

    /**
     * I campi dal modulo, controllati; se qualcosa non va, torna a `$dietro`
     * con l'errore e con quello che si era scritto.
     *
     * @return array<string, mixed>
     */
    private function campi(int $courseId, string $dietro): array
    {
        $esito = Bacheca::valida($_POST, self::idCategorie($courseId));
        $_SESSION['bacheca_old'] = $esito['dati'] + ['featured' => ($_POST['featured'] ?? '') === '1' ? 1 : 0];

        if ($esito['errore'] !== null) {
            $this->torna($dietro, $esito['errore'], false);
        }

        return $esito['dati'];
    }

    /**
     * Il file caricato, se c'e', con le regole dei materiali delle lezioni.
     * Video e podcast sono solo link: con un file, si ferma.
     *
     * @return array{stored_path: string, original_name: string, extension: string, size: int}|null
     */
    private function file(string $tipo, int $courseId, string $dietro, bool $neHaGia): ?array
    {
        $caricato = !empty($_FILES['file']['name']);

        if (in_array($tipo, Bacheca::SOLO_LINK, true) && ($caricato || $neHaGia)) {
            $this->torna($dietro, 'Video e podcast sono solo un link: togli il file, o scegli un altro tipo.', false);
        }

        if (!$caricato) {
            return null;
        }

        try {
            return Upload::store($_FILES['file'], 'bacheca/' . $courseId, Bacheca::FILE_EXTENSIONS, Bacheca::FILE_MAX_BYTES);
        } catch (\RuntimeException $e) {
            $this->torna($dietro, $e->getMessage(), false);
        }
    }

    /** @param array<string, mixed> $risorsa */
    private static function cancellaFile(array $risorsa): void
    {
        if (!empty($risorsa['file_path'])) {
            $assoluto = Upload::absolutePath((string) $risorsa['file_path']);

            if (is_file($assoluto)) {
                @unlink($assoluto);
            }
        }
    }

    /** @return array<string, mixed> */
    private static function vecchi(): array
    {
        $vecchi = $_SESSION['bacheca_old'] ?? [];
        unset($_SESSION['bacheca_old']);

        return is_array($vecchi) ? $vecchi : [];
    }

    /**
     * La risorsa, se chi chiede la gestisce (`BachecaScope::gestisce`);
     * altrimenti 403.
     *
     * @return array<string, mixed>
     */
    private function daGestire(int $id): array
    {
        Auth::requireLogin();

        $risorsa = BoardModel::risorsa($id);

        if ($risorsa === null || !BachecaScope::gestisce($risorsa)) {
            $this->ferma(403, 'Non puoi gestire questa risorsa.');
        }

        return $risorsa;
    }

    /** @return array<string, mixed> */
    private function pubblicataLeggibile(int $id): array
    {
        Auth::requireLogin();

        $risorsa = BoardModel::risorsa($id);

        if ($risorsa === null || $risorsa['status'] !== Bacheca::PUBLISHED || !BachecaScope::puoLeggere((int) $risorsa['course_id'])) {
            $this->ferma(403, 'Questa risorsa non è nella tua bacheca.');
        }

        return $risorsa;
    }

    private function leggibile(int $courseId): void
    {
        if (!BachecaScope::puoLeggere($courseId)) {
            $this->ferma(403, 'Questa bacheca è degli iscritti al corso e del suo staff.');
        }
    }

    private function ferma(int $codice, string $messaggio): never
    {
        http_response_code($codice);
        echo $messaggio;
        exit;
    }

    private function torna(string $dove, string $messaggio, bool $bene, string $ancora = ''): never
    {
        $_SESSION[$bene ? 'flash_success' : 'flash_error'] = $messaggio;
        header('Location: ' . $dove . $ancora);
        exit;
    }
}
