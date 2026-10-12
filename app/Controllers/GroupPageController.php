<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\GroupPeers;
use App\Core\CampoContato;
use App\Core\GiroDelCerchio;
use App\Core\PersonName;
use App\Core\View;
use App\Models\GroupModel;
use App\Models\GroupQuestionModel;
use App\Models\LiveSessionModel;

/**
 * La pagina di un gruppo vista da chi ne fa parte: il tutor al centro e i
 * compagni attorno, con la loro foto. Ci si arriva da «I miei gruppi» nel
 * profilo, non dalla barra laterale (deciso con Elena il 06/10: e' una
 * pagina che si apre ogni tanto, non un luogo dove si torna).
 *
 * Chi la puo' aprire lo decide `GroupPeers`, la stessa classe che decide chi
 * vede le foto.
 */
class GroupPageController
{
    public function show(array $params): void
    {
        Auth::requireLogin();

        $group = GroupModel::find((int) $params['id']);

        if ($group === null) {
            http_response_code(404);
            echo 'Gruppo non trovato.';
            return;
        }

        if (!GroupPeers::canViewGroup((int) Auth::id(), (int) $group['id'])) {
            http_response_code(403);
            echo 'Non fai parte di questo gruppo.';
            return;
        }

        // Come compaiono gli studenti (07/10): ciascuno come ha scelto nel
        // profilo — nome e cognome, solo il nome, solo le iniziali — tranne
        // per lo staff, che vede sempre tutto, e per se stessi. Il nome
        // mostrato prende il posto di `full_name`, cosi' la vista, le
        // iniziali della foto e la scheda della presentazione usano tutti lo
        // stesso. Il tutor al centro compare sempre per intero.
        $chiGuarda = (int) Auth::id();
        $vedeTutto = Auth::hasRole('admin', 'tutor');
        $people = array_map(
            static fn (array $p): array => [
                'full_name' => PersonName::shown($p, $chiGuarda, $vedeTutto),
                // Lo studente vede se stesso per intero (come in tutta la
                // piattaforma), e sotto, solo lui, come lo vedono gli altri
                // (08/10, Elena): e' l'unico modo di verificare la propria
                // scelta. Allo staff no: compare sempre per intero.
                'come_ti_vedono' => (int) $p['id'] === $chiGuarda && !$vedeTutto
                    ? PersonName::shown($p, 0, false)
                    : null,
            ] + $p,
            GroupModel::peopleForPage((int) $group['id'])
        );

        // In ordine di nome mostrato, non di nome vero: chi ha scelto le
        // iniziali non deve finire in mezzo a chi ha il suo stesso cognome.
        usort($people, static fn (array $a, array $b): int
            => strcmp(mb_strtolower((string) $a['full_name']), mb_strtolower((string) $b['full_name'])) ?: ((int) $a['id'] <=> (int) $b['id']));

        $groupId = (int) $group['id'];
        $memberIds = array_map('intval', GroupModel::memberIds($groupId));
        $modera = GiroDelCerchio::modera($group, $chiGuarda, Auth::hasRole('admin'));
        $partecipa = GiroDelCerchio::partecipa($memberIds, $chiGuarda);

        // Il giro del cerchio (12/10): la domanda aperta e le risposte.
        $domanda = GroupQuestionModel::current($groupId);
        $risposte = [];
        $contate = 0;

        if ($domanda !== null) {
            $tutte = GroupQuestionModel::answers((int) $domanda['id']);
            $contate = GiroDelCerchio::contate($tutte);
            $risposte = self::conNomi(GiroDelCerchio::visibili($tutte, $chiGuarda, $modera), $chiGuarda, $vedeTutto);
        }

        // Il prossimo incontro del gruppo, con chi ha detto «Ci sarò».
        $incontro = LiveSessionModel::prossimoDelGruppo($groupId);
        $ciSaranno = [];

        if ($incontro !== null) {
            $ciSaranno = array_map(
                static fn (array $p): array => ['full_name' => PersonName::shown($p, $chiGuarda, $vedeTutto)] + $p,
                LiveSessionModel::ciSaranno((int) $incontro['id'])
            );
        }

        // Un testo rifiutato (troppo lungo, vuoto) torna nel campo.
        $daRiprendere = $_SESSION['gruppo_da_riprendere'] ?? null;
        unset($_SESSION['gruppo_da_riprendere']);

        View::render('profile/group', [
            'pageTitle' => self::titolo((string) $group['name']),
            'group' => $group,
            'tutor' => GroupModel::tutorForPage($groupId),
            'people' => $people,
            'partecipanti' => count($memberIds),
            'modera' => $modera,
            'partecipa' => $partecipa,
            'domanda' => $domanda,
            'risposte' => $risposte,
            'contate' => $contate,
            'archivio' => GroupQuestionModel::countArchive($groupId),
            'incontro' => $incontro,
            'ciSaranno' => $ciSaranno,
            'ciSaro' => in_array($chiGuarda, array_map(static fn (array $p): int => (int) $p['id'], $ciSaranno), true),
            'chiGuarda' => $chiGuarda,
            'daRiprendere' => is_array($daRiprendere) && (int) ($daRiprendere['gruppo'] ?? 0) === $groupId ? $daRiprendere : null,
        ]);
    }

    /** Le domande passate, con le loro risposte: si leggono e basta. */
    public function archive(array $params): void
    {
        [$group, $chiGuarda, $modera] = $this->gruppoVisibile((int) $params['id']);
        $vedeTutto = Auth::hasRole('admin', 'tutor');

        $domande = array_map(
            static fn (array $q): array => $q + ['risposte' => self::conNomi(
                GiroDelCerchio::visibili(GroupQuestionModel::answers((int) $q['id']), $chiGuarda, $modera),
                $chiGuarda,
                $vedeTutto
            )],
            GroupQuestionModel::archive((int) $group['id'])
        );

        View::render('profile/group_archive', [
            'pageTitle' => 'Domande passate · ' . self::titolo((string) $group['name']),
            'group' => $group,
            'domande' => $domande,
            'modera' => $modera,
            'chiGuarda' => $chiGuarda,
        ]);
    }

    /** Una domanda nuova al gruppo: la precedente passa all'archivio. */
    public function ask(array $params): void
    {
        [$group, $chiGuarda, $modera] = $this->gruppoVisibile((int) $params['id']);
        $qui = '/gruppi/' . (int) $group['id'];

        if (!$modera) {
            $this->vietato('Solo il tutor del gruppo e l\'amministrazione pongono le domande al gruppo.');
        }

        $testo = CampoContato::taglia((string) ($_POST['question'] ?? ''), GroupQuestionModel::MAX_DOMANDA);

        if ($testo === '') {
            $this->torna($qui . '#giro', 'Scrivi la domanda da porre al gruppo.', false);
        }

        GroupQuestionModel::create((int) $group['id'], $chiGuarda, $testo);
        $this->torna($qui . '#giro', 'Domanda posta al gruppo. La precedente, con le sue risposte, è fra le domande passate.', true);
    }

    /** La propria risposta alla domanda aperta: scriverla, cambiarla, toglierla. */
    public function answer(array $params): void
    {
        [$group, $chiGuarda] = $this->gruppoVisibile((int) $params['id']);
        $groupId = (int) $group['id'];
        $qui = '/gruppi/' . $groupId . '#giro';

        if (!GiroDelCerchio::partecipa(array_map('intval', GroupModel::memberIds($groupId)), $chiGuarda)) {
            $this->vietato('Rispondono i partecipanti del gruppo.');
        }

        $domanda = GroupQuestionModel::current($groupId);

        // Si risponde solo alla domanda aperta: se nel frattempo ne e' arrivata
        // un'altra, la risposta non finisce su quella sbagliata.
        if ($domanda === null || (int) $domanda['id'] !== (int) ($_POST['question_id'] ?? 0)) {
            $this->torna($qui, 'Nel frattempo è arrivata una domanda nuova: rispondi a questa.', false);
        }

        $esistente = GroupQuestionModel::answerOf((int) $domanda['id'], $chiGuarda);

        if ($esistente !== null && $esistente['hidden_at'] !== null) {
            $this->torna($qui, 'La tua risposta è stata nascosta: non si può più cambiare.', false);
        }

        $grezzo = str_replace("\r\n", "\n", trim((string) ($_POST['answer'] ?? '')));

        if (mb_strlen($grezzo) > GroupQuestionModel::MAX_RISPOSTA) {
            $_SESSION['gruppo_da_riprendere'] = ['gruppo' => $groupId, 'answer' => $grezzo];
            $this->torna($qui, 'La risposta supera di ' . (mb_strlen($grezzo) - GroupQuestionModel::MAX_RISPOSTA)
                . ' caratteri il limite di ' . GroupQuestionModel::MAX_RISPOSTA . '.', false);
        }

        if ($grezzo === '') {
            GroupQuestionModel::deleteAnswer((int) $domanda['id'], $chiGuarda);
            $this->torna($qui, $esistente !== null ? 'Risposta tolta.' : 'Scrivi la tua risposta.', $esistente !== null);
        }

        GroupQuestionModel::saveAnswer((int) $domanda['id'], $chiGuarda, $grezzo);
        $this->torna($qui, $esistente !== null ? 'Risposta cambiata.' : 'Risposta condivisa con il gruppo.', true);
    }

    /** Nasconde una risposta, o la rimette (`$params['come']`). */
    public function moderate(array $params): void
    {
        Auth::requireLogin();

        $risposta = GroupQuestionModel::findAnswer((int) $params['answerId']);

        if ($risposta === null) {
            http_response_code(404);
            echo 'Risposta non trovata.';
            exit;
        }

        [$group, $chiGuarda, $modera] = $this->gruppoVisibile((int) $risposta['group_id']);

        if (!$modera) {
            $this->vietato('Solo il tutor del gruppo e l\'amministrazione nascondono le risposte.');
        }

        $nascondi = ($_POST['azione'] ?? '') === 'nascondi';
        GroupQuestionModel::setHidden((int) $risposta['id'], $nascondi ? $chiGuarda : null);

        $aperta = GroupQuestionModel::current((int) $group['id']);
        $dove = $aperta !== null && (int) $aperta['id'] === (int) $risposta['question_id']
            ? '/gruppi/' . (int) $group['id'] . '#risposta-' . (int) $risposta['id']
            : '/gruppi/' . (int) $group['id'] . '/domande-passate#risposta-' . (int) $risposta['id'];

        $this->torna($dove, $nascondi
            ? 'Risposta nascosta: ora la vede solo chi l\'ha scritta.'
            : 'Risposta di nuovo visibile al gruppo.', true);
    }

    /** «Ci sarò» per il prossimo incontro del gruppo, e il suo contrario. */
    public function rsvp(array $params): void
    {
        [$group, $chiGuarda] = $this->gruppoVisibile((int) $params['id']);
        $groupId = (int) $group['id'];
        $qui = '/gruppi/' . $groupId;

        if (!GiroDelCerchio::partecipa(array_map('intval', GroupModel::memberIds($groupId)), $chiGuarda)) {
            $this->vietato('Dicono «Ci sarò» i partecipanti del gruppo.');
        }

        $incontro = LiveSessionModel::prossimoDelGruppo($groupId);

        if ($incontro === null || (int) $incontro['id'] !== (int) ($_POST['session_id'] ?? 0)) {
            $this->torna($qui, 'Questo incontro non è più il prossimo del gruppo.', false);
        }

        $ci = ($_POST['ci'] ?? '') === '1';
        LiveSessionModel::segnaCiSaro((int) $incontro['id'], $chiGuarda, $ci);
        $this->torna($qui, $ci ? 'Segnato: ci sarai.' : 'Tolto: non ci sarai.', true);
    }

    // ---------------------------------------------------------------

    /**
     * Il gruppo, se chi e' collegato lo puo' vedere; altrimenti 404 o 403.
     *
     * @return array{0: array<string, mixed>, 1: int, 2: bool}
     */
    private function gruppoVisibile(int $groupId): array
    {
        Auth::requireLogin();

        $group = GroupModel::find($groupId);

        if ($group === null) {
            http_response_code(404);
            echo 'Gruppo non trovato.';
            exit;
        }

        $chiGuarda = (int) Auth::id();

        if (!GroupPeers::canViewGroup($chiGuarda, $groupId)) {
            $this->vietato('Non fai parte di questo gruppo.');
        }

        return [$group, $chiGuarda, GiroDelCerchio::modera($group, $chiGuarda, Auth::hasRole('admin'))];
    }

    /**
     * Le risposte con il nome come lo vede chi guarda (le stesse regole del
     * cerchio) e il segno «tu».
     *
     * @param list<array<string, mixed>> $risposte
     * @return list<array<string, mixed>>
     */
    private static function conNomi(array $risposte, int $chiGuarda, bool $vedeTutto): array
    {
        return array_map(static function (array $r) use ($chiGuarda, $vedeTutto): array {
            $persona = ['id' => (int) $r['persona_id'], 'full_name' => $r['full_name'], 'first_name' => $r['first_name'],
                'last_name' => $r['last_name'], 'name_display' => $r['name_display']];

            return ['nome' => PersonName::shown($persona, $chiGuarda, $vedeTutto), 'mia' => (int) $r['user_id'] === $chiGuarda] + $r;
        }, $risposte);
    }

    private function torna(string $dove, string $messaggio, bool $bene): never
    {
        $_SESSION[$bene ? 'flash_success' : 'flash_error'] = $messaggio;
        header('Location: ' . $dove);
        exit;
    }

    private function vietato(string $messaggio): never
    {
        http_response_code(403);
        echo $messaggio;
        exit;
    }

    /**
     * «Gruppo Verde», non «Verde» (chiesto da Elena il 06/10): i nomi dei
     * gruppi sono spesso un colore o una parola sola, che da soli in cima
     * alla pagina non dicono di che cosa si tratta. Se il nome comincia gia'
     * con «Gruppo» la parola non si ripete.
     */
    public static function titolo(string $nome): string
    {
        return preg_match('/^gruppo\b/iu', $nome) === 1 ? $nome : 'Gruppo ' . $nome;
    }
}
