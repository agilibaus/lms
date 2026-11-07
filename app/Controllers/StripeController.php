<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\AvvisoStripe;
use App\Core\CorsiAPagamento;
use App\Core\Mail\CodaEmail;
use App\Core\Mail\Mailer;
use App\Core\Settings;
use App\Core\Url;
use App\Core\View;
use App\Models\CourseModel;
use App\Models\EnrollmentModel;
use App\Models\PaymentModel;
use App\Models\QuestionModel;
use App\Models\UserModel;

/**
 * Gli avvisi di Stripe (06/11): `POST /stripe/avvisi`, chiamato da Stripe e
 * non da un modulo della piattaforma, quindi senza token CSRF
 * (`Router::postEsterna`). La provenienza la garantisce la firma.
 *
 * Lo studente paga con il link di pagamento del corso; il pulsante porta il
 * suo numero (`client_reference_id`). Quando il pagamento e' completato,
 * Stripe manda qui l'avviso: il corso si riconosce dall'identificativo del
 * link (`payment_link`), lo studente dal numero, il prezzo prima degli
 * sconti deve essere quello del corso. Se tutto torna, lo studente e'
 * iscritto con la stessa funzione dell'iscrizione a mano, e l'«Iscrizione
 * confermata» la manda Pistacchio (la ricevuta la manda Stripe). Se no, il
 * pagamento resta in rosso in «Impostazioni › Pagamenti» e l'Amministratore
 * riceve un'email: lo studente si iscrive a mano o il pagamento si rimborsa
 * su Stripe.
 *
 * Le risposte: 400 a un avviso senza firma giusta (Stripe lo segnala nel suo
 * pannello); 200 a tutto il resto, anche a un avviso che non iscrive nessuno,
 * perche' ripeterlo non cambierebbe niente; 500 solo se qualcosa si rompe,
 * cosi' Stripe ritenta.
 */
class StripeController
{
    public function avvisi(array $params = []): void
    {
        $corpo = (string) file_get_contents('php://input');
        $chiave = (string) Settings::get(CorsiAPagamento::KEY_SEGRETO, '');

        if (!AvvisoStripe::firmaValida($corpo, (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''), $chiave, time())) {
            $this->rispondi(400, $chiave === '' ? 'Chiave degli avvisi non impostata.' : 'Firma non valida.');
        }

        $evento = json_decode($corpo, true);

        if (!is_array($evento) || !AvvisoStripe::daContare($evento)) {
            $this->rispondi(200, 'Avviso ignorato.');
        }

        /** @var array<string, mixed> $sessione */
        $sessione = $evento['data']['object'];
        $eventId = (string) ($evento['id'] ?? '');
        $sessionId = (string) ($sessione['id'] ?? '');

        if ($eventId === '' || $sessionId === '') {
            $this->rispondi(200, 'Avviso senza identificativi.');
        }

        if (PaymentModel::giaContato($eventId, $sessionId)) {
            $this->rispondi(200, 'Pagamento già contato.');
        }

        $corso = CourseModel::perIdentificativo((string) ($sessione['payment_link'] ?? ''));
        $studente = null;
        $numero = (string) ($sessione['client_reference_id'] ?? '');

        if (ctype_digit($numero)) {
            $trovato = UserModel::find((int) $numero);
            $studente = $trovato !== null && (int) $trovato['is_active'] === 1 ? $trovato : null;
        }

        $giaIscritto = $corso !== null && $studente !== null
            && EnrollmentModel::find((int) $studente['id'], (int) $corso['id']) !== null;
        $motivo = AvvisoStripe::motivo($sessione, CorsiAPagamento::inProva(), $corso, $studente, $giaIscritto);

        // L'interruttore generale non conta qui (06/11): chi ha pagato,
        // anche da un link copiato prima di spegnerlo, viene iscritto.
        if ($motivo === null && $corso !== null && $studente !== null) {
            EnrollmentModel::enroll((int) $studente['id'], (int) $corso['id'], true);

            if (EnrollmentModel::find((int) $studente['id'], (int) $corso['id']) === null) {
                $motivo = 'L’iscrizione non è riuscita.';
            }
        }

        $email = $sessione['customer_details']['email'] ?? null;
        $scritto = PaymentModel::registra([
            'event' => $eventId,
            'session' => $sessionId,
            'livemode' => (bool) ($sessione['livemode'] ?? false),
            'course_id' => $corso !== null ? (int) $corso['id'] : null,
            'user_id' => $studente !== null ? (int) $studente['id'] : null,
            'email' => is_string($email) ? mb_substr($email, 0, 255) : null,
            'total' => (int) ($sessione['amount_total'] ?? 0),
            'subtotal' => (int) ($sessione['amount_subtotal'] ?? 0),
            'currency' => mb_substr((string) ($sessione['currency'] ?? ''), 0, 10),
            'esito' => $motivo === null ? 'iscritto' : 'non_riuscito',
            'motivo' => $motivo,
        ]);

        if ($scritto && $motivo !== null) {
            $this->avvisaAmministratore($corso, $studente, is_string($email) ? $email : '', (int) ($sessione['amount_total'] ?? 0), $motivo);
        }

        $this->rispondi(200, $motivo === null ? 'Iscrizione completata.' : 'Pagamento registrato, iscrizione non riuscita.');
    }

    /**
     * La pagina d'attesa dopo il pagamento (06/11, variante 1A): Stripe ci
     * rimanda lo studente con il codice del pagamento. La pagina si ricarica
     * ogni secondo, al massimo per `ATTESA_SECONDI`: appena il pagamento
     * risulta registrato porta a «I miei corsi»; a tempo scaduto ci porta lo
     * stesso, con il codice, e li' un avviso dice che la conferma non e'
     * ancora arrivata. La ricarica e' un'intestazione `Refresh`: funziona
     * anche senza JavaScript. Un pagamento registrato in rosso porta a «I miei
     * corsi» senza avviso: lo gestisce l'Amministratore, con l'email.
     */
    public function ricevuto(array $params = []): void
    {
        Auth::requireLogin();

        $sessione = (string) ($_GET['sessione'] ?? '');
        $giro = max(0, (int) ($_GET['giro'] ?? 0));

        if ($sessione === '' || PaymentModel::registratoPer($sessione, (int) Auth::id())) {
            header('Location: /');
            exit;
        }

        if ($giro >= CorsiAPagamento::ATTESA_SECONDI) {
            header('Location: /?pagamento=' . rawurlencode($sessione));
            exit;
        }

        header('Refresh: 1; url=' . CorsiAPagamento::INDIRIZZO_RITORNO . '?' . http_build_query(['sessione' => $sessione, 'giro' => $giro + 1]));

        View::render('pagamento/ricevuto', ['pageTitle' => 'Pagamento ricevuto']);
    }

    /**
     * @param array<string, mixed>|null $corso
     * @param array<string, mixed>|null $studente
     */
    private function avvisaAmministratore(?array $corso, ?array $studente, string $email, int $importo, string $motivo): void
    {
        $chi = $studente !== null ? (string) $studente['full_name'] : ($email !== '' ? $email : 'non riconosciuto');
        $titolo = $corso !== null ? (string) $corso['title'] : 'non riconosciuto';

        foreach (QuestionModel::adminRecipients() as $d) {
            CodaEmail::aggiungi(Mailer::pagamentoNonRiuscito(
                (string) $d['email'],
                (string) $d['full_name'],
                $titolo,
                $chi,
                CorsiAPagamento::prezzo($importo),
                $motivo,
                Url::to('/admin/settings/pagamenti')
            ));
        }
    }

    private function rispondi(int $stato, string $testo): never
    {
        http_response_code($stato);
        header('Content-Type: text/plain; charset=utf-8');
        echo $testo;
        exit;
    }
}
