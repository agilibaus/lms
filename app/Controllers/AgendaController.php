<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Agenda;
use App\Core\Ics;
use App\Core\Url;
use App\Core\View;
use App\Models\AgendaModel;

/**
 * L'agenda: le cose con una data che riguardano chi guarda.
 *
 * DUE VISTE E NON QUATTRO. L'elenco e il mese. La settimana e il giorno
 * sono state lasciate fuori con una ragione misurabile: senza orari fitti
 * mostrerebbero le stesse due o tre righe dell'elenco occupando uno
 * schermo intero, e sono anche le due viste piu' difficili da far stare in
 * 320 px. Se un giorno gli incontri saranno molti, si aggiungono: la forma
 * degli eventi in `App\Core\Agenda` e' gia' quella giusta.
 *
 * L'elenco e' la vista d'ingresso perche' risponde alla domanda con cui si
 * apre un'agenda — «che cosa mi aspetta adesso» — e perche' funziona
 * identica sul telefono. Il mese serve al colpo d'occhio.
 *
 * NON E' SOLO PER GLI STUDENTI. Lo staff ha la propria agenda con le
 * proprie cose: un tutor vede gli incontri dei suoi corsi e gruppi, come
 * li vede in `/live`. La pagina non ha un permesso suo, perche' non mostra
 * niente che chi guarda non possa gia' vedere altrove: e' un altro modo di
 * guardare le stesse righe.
 */
class AgendaController
{
    public function index(array $params = []): void
    {
        Auth::requireLogin();

        $userId = (int) Auth::id();
        $eventi = Agenda::eventiDi($userId);

        $vista = ($_GET['vista'] ?? '') === 'mese' ? 'mese' : 'elenco';
        $mese = Agenda::meseChiesto(isset($_GET['mese']) && is_string($_GET['mese']) ? $_GET['mese'] : null);

        View::render('agenda/index', [
            'pageTitle' => 'Agenda',
            'vista' => $vista,
            'mese' => $mese,
            'eventi' => $eventi,
            'gruppi' => Agenda::raggruppa($eventi),
            'settimane' => Agenda::griglia($mese, $eventi),
            'token' => AgendaModel::tokenDi($userId),
        ]);
    }

    /**
     * Crea o rigenera l'indirizzo personale del calendario.
     *
     * Rigenerare **spegne il link di prima**: e' una revoca, ed e' il
     * motivo per cui e' una POST con il suo gettone CSRF e non un
     * collegamento che si potrebbe seguire per sbaglio.
     */
    public function rigenera(array $params = []): void
    {
        // Il gettone CSRF lo verifica il router su ogni POST, prima di
        // arrivare qui: non si ricontrolla, altrimenti diventano due posti
        // in cui ricordarsi di farlo.
        Auth::requireLogin();

        AgendaModel::rigeneraToken((int) Auth::id());

        $_SESSION['flash_success'] = 'Indirizzo del calendario creato. '
            . 'Il collegamento precedente, se c\'era, non funziona più.';
        header('Location: /agenda');
        exit;
    }

    public function dimentica(array $params = []): void
    {
        Auth::requireLogin();

        AgendaModel::dimenticaToken((int) Auth::id());

        $_SESSION['flash_success'] = 'Indirizzo del calendario disattivato.';
        header('Location: /agenda');
        exit;
    }

    /**
     * Il singolo incontro in formato .ics, da aggiungere al calendario.
     *
     * Chiede l'accesso come ogni altra pagina: e' un comando che sta
     * dentro all'agenda, non un indirizzo da passare in giro.
     */
    public function evento(array $params): void
    {
        Auth::requireLogin();

        $id = (int) ($params['id'] ?? 0);
        $eventi = Agenda::eventiDi((int) Auth::id());

        foreach ($eventi as $evento) {
            if ($evento['tipo'] === Agenda::INCONTRO && $evento['id'] === $id) {
                $this->inviaIcs(
                    Ics::publish([$this->perIcs($evento)], $evento['titolo']),
                    'incontro-' . $id . '.ics'
                );

                return;
            }
        }

        // Un incontro che non e' fra i suoi non esiste, per chi chiede:
        // non «c'e' ma non e' tuo», che sarebbe gia' un'informazione.
        http_response_code(404);
        echo 'Evento non trovato.';
    }

    /**
     * Il calendario personale da sottoscrivere.
     *
     * **Questa e' l'unica pagina della piattaforma che risponde senza
     * accesso**, e lo fa perche' deve: un calendario sottoscritto lo
     * rilegge un programma, da solo, senza nessuno davanti che possa
     * scrivere una password. Al posto dell'accesso c'e' il token
     * nell'indirizzo, che per questo e' una credenziale a tutti gli
     * effetti — 24 byte casuali, revocabile, e detto chiaramente a chi lo
     * crea.
     *
     * Tre conseguenze, tutte volute:
     *  - il token non si conferma mai («esiste ma e' di un altro» sarebbe
     *    gia' un'informazione): o si trova il proprietario, o 404;
     *  - un utente disattivato non ha piu' calendario, anche se il link
     *    gli e' rimasto nel telefono (lo esclude `AgendaModel::perToken`);
     *  - la risposta non si mette in cache dai proxy, perche' e' roba di
     *    una persona sola.
     */
    public function feed(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $utente = AgendaModel::perToken($token);

        if ($utente === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Calendario non trovato.';
            return;
        }

        $eventi = array_map(
            fn (array $e): array => $this->perIcs($e),
            Agenda::eventiDi((int) $utente['id'])
        );

        header('Cache-Control: private, no-store');
        $this->inviaIcs(Ics::publish($eventi, 'Pistacchio · ' . $utente['full_name']), 'agenda.ics');
    }

    // ---------------------------------------------------------------

    /**
     * Un evento dell'agenda nella forma che vuole `Ics::publish`.
     *
     * @param array<string, mixed> $evento
     * @return array<string, mixed>
     */
    private function perIcs(array $evento): array
    {
        $titolo = $evento['tipo'] === Agenda::APERTURA
            // Nel calendario di chi legge, «Modulo 3» da solo non si
            // capisce: il titolo deve dire che cosa succede.
            ? 'Si apre: ' . $evento['titolo']
            : $evento['titolo'];

        return [
            'uid' => $evento['tipo'] . '-' . $evento['id'] . '@pistacchio',
            'titolo' => $titolo,
            'inizio' => $evento['inizio'],
            'fine' => $evento['fine'],
            'descrizione' => $evento['contesto'],
            'luogo' => Url::to($evento['url']),
        ];
    }

    private function inviaIcs(string $contenuto, string $nomeFile): void
    {
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nomeFile . '"');
        header('Content-Length: ' . strlen($contenuto));
        echo $contenuto;
    }
}
