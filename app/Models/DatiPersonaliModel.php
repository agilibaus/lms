<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Bacheca;
use App\Core\Database;
use App\Core\PersonName;

/**
 * «Scarica i tuoi dati» (19/11, Alessandro): i dati di uno studente per il
 * file XLSX che scarica dal profilo (il diritto di accesso del GDPR, art. 15).
 *
 * Sei fogli, uno per argomento (scelta di Alessandro: venti, uno per
 * tabella, erano troppi): Profilo, Corsi, Lezioni e video, Questionari,
 * Incontri dal vivo, Quello che hai scritto. Ci sono sempre, anche vuoti,
 * nello stesso ordine. Dove un foglio mette insieme cose diverse, alcune
 * colonne restano vuote per certe righe: e' il prezzo accettato.
 *
 * Due regole, le stesse in ogni foglio:
 * - solo quello che e' suo: le sue risposte nel giro del cerchio, non quelle
 *   dei compagni; le sue proposte in Bacheca, non quelle degli altri. Il nome
 *   del gruppo, del corso e la risposta dell'esperto si', perche' sono il
 *   suo percorso;
 * - niente credenziali e niente dati di sicurezza: impronta della password,
 *   token del calendario, di reimpostazione e di conferma, tentativi di
 *   accesso falliti. La foto non c'e': lo studente la vede gia' dal profilo.
 */
class DatiPersonaliModel
{
    /** Separatore fra piu' valori nella stessa cella. */
    private const SEP = ' · ';

    /**
     * @return array<int, array{nome: string, intestazione: string[],
     *         righe: array<int, array<int, mixed>>, tipi: array<int, string>}>
     */
    public static function fogli(int $userId): array
    {
        return [
            self::profilo($userId),
            self::corsi($userId),
            self::lezioni($userId),
            self::questionari($userId),
            self::incontri($userId),
            self::scritti($userId),
        ];
    }

    /** Il profilo e i gruppi: una riga per dato, perche' sono dati di tipi diversi. */
    private static function profilo(int $userId): array
    {
        $u = self::righe(
            'SELECT u.first_name, u.last_name, u.email, u.phone, u.city, u.bio, u.name_display,
                    u.created_at, u.email_verified_at, u.last_seen_at, u.password_changed_at,
                    u.deletion_requested_at, p.published_at AS privacy_published_at
               FROM users u LEFT JOIN privacy_versions p ON p.id = u.privacy_version_id
              WHERE u.id = :u',
            $userId
        )[0] ?? [];

        $comeTiVedono = $u['name_display'] ?? null;
        $comeTiVedono = $comeTiVedono === null
            ? 'Non ancora scelto: gli altri studenti vedono le tue iniziali'
            : (PersonName::SCELTE[$comeTiVedono] ?? (string) $comeTiVedono);

        $righe = [
            ['Nome', $u['first_name'] ?? ''],
            ['Cognome', $u['last_name'] ?? ''],
            ['Email', $u['email'] ?? ''],
            ['Telefono', $u['phone'] ?? ''],
            ['Città', $u['city'] ?? ''],
            ['Presentazione', $u['bio'] ?? ''],
            ['Come ti vedono gli altri studenti', $comeTiVedono],
            ['Registrazione', self::quando($u['created_at'] ?? null)],
            ['Email confermata il', self::quando($u['email_verified_at'] ?? null)],
            ['Ultima attività', self::quando($u['last_seen_at'] ?? null)],
            ['Password cambiata l\'ultima volta il', self::quando($u['password_changed_at'] ?? null)],
            ['Informativa sulla privacy ricevuta', isset($u['privacy_published_at'])
                ? 'Versione del ' . self::giorno((string) $u['privacy_published_at']) : ''],
            ['Cancellazione chiesta il', self::quando($u['deletion_requested_at'] ?? null)],
        ];

        foreach (self::righe(
            'SELECT g.name, gm.joined_at
               FROM group_members gm JOIN `groups` g ON g.id = gm.group_id
              WHERE gm.user_id = :u ORDER BY gm.joined_at, gm.id', $userId) as $g) {
            $righe[] = ['Gruppo', $g['name'] . ($g['joined_at'] !== null ? ' (dal ' . self::giorno($g['joined_at']) . ')' : '')];
        }

        return self::foglio('Profilo', ['Dato', 'Valore'], [], $righe);
    }

    /**
     * Una riga per corso, con tutto quello che lo riguarda: richiesta,
     * pagamento, iscrizione, certificato, avvisi di apertura dei moduli.
     * Un corso compare se c'e' almeno una di queste cose.
     */
    private static function corsi(int $userId): array
    {
        /** @var array<string, array<string, mixed>> $corsi */
        $corsi = [];
        $corso = static function (?int $id, ?string $titolo) use (&$corsi): string {
            $chiave = $id === null ? 'nessuno' : (string) $id;
            $corsi[$chiave] ??= ['titolo' => $titolo ?? '(corso eliminato)'];

            return $chiave;
        };

        foreach (self::righe(
            'SELECT e.course_id, c.title, e.enrolled_at, e.progress_pct, e.completed_at, e.last_visit_at
               FROM enrollments e JOIN courses c ON c.id = e.course_id
              WHERE e.user_id = :u ORDER BY e.enrolled_at, e.id', $userId) as $r) {
            $k = $corso((int) $r['course_id'], $r['title']);
            $corsi[$k] += ['iscritto' => $r['enrolled_at'], 'avanzamento' => $r['progress_pct'],
                'completato' => $r['completed_at'], 'visita' => $r['last_visit_at']];
        }

        $statiRichiesta = ['pending' => 'In attesa', 'approved' => 'Accettata', 'rejected' => 'Non accettata'];
        foreach (self::righe(
            'SELECT r.course_id, c.title, r.status, r.message, r.requested_at
               FROM enrollment_requests r JOIN courses c ON c.id = r.course_id
              WHERE r.user_id = :u ORDER BY r.requested_at, r.id', $userId) as $r) {
            $k = $corso((int) $r['course_id'], $r['title']);
            $corsi[$k]['richiesta'] = $statiRichiesta[$r['status']] ?? $r['status'];
            $corsi[$k]['messaggio'] = $r['message'];
            $corsi[$k]['richiesta_il'] = $r['requested_at'];
        }

        foreach (self::righe(
            'SELECT p.course_id, c.title, p.amount_total, p.currency, p.esito, p.email, p.received_at
               FROM payments p LEFT JOIN courses c ON c.id = p.course_id
              WHERE p.user_id = :u ORDER BY p.received_at, p.id', $userId) as $r) {
            $k = $corso($r['course_id'] === null ? null : (int) $r['course_id'], $r['title']);
            $importo = number_format((int) $r['amount_total'] / 100, 2, ',', '.') . ' ' . strtoupper((string) $r['currency']);
            $esito = $r['esito'] === 'iscritto' ? 'Pagato' : 'Non riuscito';
            $corsi[$k]['pagamenti'][] = $esito . ', ' . $importo . ', ' . self::quando($r['received_at'])
                . ($r['email'] !== null && $r['email'] !== '' ? ', email indicata ' . $r['email'] : '');
        }

        foreach (self::righe(
            'SELECT ce.course_id, c.title, ce.certificate_code, ce.issued_at, ce.revoked_at
               FROM certificates ce JOIN courses c ON c.id = ce.course_id
              WHERE ce.user_id = :u ORDER BY ce.issued_at, ce.id', $userId) as $r) {
            $k = $corso((int) $r['course_id'], $r['title']);
            $corsi[$k]['certificato'] = $r['certificate_code'];
            $corsi[$k]['emesso'] = $r['issued_at'];
            $corsi[$k]['revocato'] = $r['revoked_at'];
        }

        foreach (self::righe(
            'SELECT m.course_id, c.title, m.title AS modulo, n.sent_at
               FROM module_unlock_notifications n
               JOIN modules m ON m.id = n.module_id
               JOIN courses c ON c.id = m.course_id
              WHERE n.user_id = :u ORDER BY n.sent_at, n.id', $userId) as $r) {
            $k = $corso((int) $r['course_id'], $r['title']);
            $corsi[$k]['avvisi'][] = $r['modulo'] . ' (' . self::quando($r['sent_at']) . ')';
        }

        $righe = [];
        foreach ($corsi as $c) {
            $righe[] = [
                $c['titolo'],
                $c['richiesta'] ?? null,
                $c['messaggio'] ?? null,
                $c['richiesta_il'] ?? null,
                isset($c['pagamenti']) ? implode(self::SEP, $c['pagamenti']) : null,
                $c['iscritto'] ?? null,
                $c['avanzamento'] ?? null,
                $c['completato'] ?? null,
                $c['visita'] ?? null,
                $c['certificato'] ?? null,
                $c['emesso'] ?? null,
                $c['revocato'] ?? null,
                isset($c['avvisi']) ? implode(self::SEP, $c['avvisi']) : null,
            ];
        }

        return self::foglio('Corsi', [
            'Corso', 'Richiesta di iscrizione', 'Il tuo messaggio', 'Richiesta il', 'Pagamento',
            'Iscritto il', 'Avanzamento (%)', 'Completato il', 'Ultima visita',
            'Certificato', 'Certificato emesso il', 'Certificato revocato il', 'Email di apertura dei moduli',
        ], ['testo', 'testo', 'testo', 'data', 'testo', 'data', 'numero', 'data', 'data', 'testo', 'data', 'data', 'testo'], $righe);
    }

    /**
     * Una riga per lezione toccata: completata, video avviato o guardato,
     * materiali scaricati. I tratti guardati e i materiali stanno in una
     * cella sola.
     */
    private static function lezioni(int $userId): array
    {
        $tratti = [];
        foreach (self::righe(
            'SELECT lesson_id, start_seconds, end_seconds FROM lesson_video_intervals
              WHERE user_id = :u ORDER BY lesson_id, start_seconds, id', $userId) as $r) {
            $tratti[(int) $r['lesson_id']][] = $r['start_seconds'] . '–' . $r['end_seconds'] . ' s';
        }

        $materiali = [];
        foreach (self::righe(
            'SELECT lm.lesson_id, lm.file_name, d.first_at
               FROM material_downloads d JOIN lesson_materials lm ON lm.id = d.material_id
              WHERE d.user_id = :u ORDER BY d.first_at', $userId) as $r) {
            $materiali[(int) $r['lesson_id']][] = $r['file_name'] . ' (' . self::quando($r['first_at']) . ')';
        }

        $righe = [];
        foreach (self::righe(
            'SELECT l.id, c.title AS corso, m.title AS modulo, l.title AS lezione,
                    lp.completed_at, v.position_seconds, v.duration_seconds, v.first_seen_at, v.updated_at
               FROM lessons l
               JOIN modules m ON m.id = l.module_id
               JOIN courses c ON c.id = m.course_id
               LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.user_id = :u1
               LEFT JOIN lesson_video_progress v ON v.lesson_id = l.id AND v.user_id = :u2
              WHERE lp.id IS NOT NULL OR v.user_id IS NOT NULL
                 OR l.id IN (SELECT lesson_id FROM lesson_video_intervals WHERE user_id = :u3)
                 OR l.id IN (SELECT lm.lesson_id FROM material_downloads d
                               JOIN lesson_materials lm ON lm.id = d.material_id WHERE d.user_id = :u4)
              ORDER BY c.id, m.position, m.id, l.position, l.id', $userId) as $r) {
            $id = (int) $r['id'];
            $righe[] = [
                $r['corso'], $r['modulo'], $r['lezione'], $r['completed_at'],
                $r['position_seconds'], $r['duration_seconds'], $r['first_seen_at'], $r['updated_at'],
                isset($tratti[$id]) ? implode(self::SEP, $tratti[$id]) : null,
                isset($materiali[$id]) ? implode(self::SEP, $materiali[$id]) : null,
            ];
        }

        return self::foglio('Lezioni e video', [
            'Corso', 'Modulo', 'Lezione', 'Completata il', 'Video: arrivato a (secondi)', 'Durata del video (secondi)',
            'Video avviato la prima volta', 'Video, ultimo aggiornamento', 'Tratti di video guardati', 'Materiali scaricati',
        ], ['testo', 'testo', 'testo', 'data', 'numero', 'numero', 'data', 'data', 'testo', 'testo'], $righe);
    }

    /**
     * Una riga per risposta, con i dati del tentativo accanto. Una scelta
     * multipla ha una riga per opzione scelta nel database: qui diventano
     * una riga per domanda. Un tentativo senza domande (H5P) ha una riga sola.
     */
    private static function questionari(int $userId): array
    {
        $righe = [];

        foreach (self::righe(
            'SELECT a.id AS tentativo, c.title AS corso, m.title AS modulo, q.title AS questionario,
                    a.attempted_at, a.score_pct, a.passed,
                    qq.id AS domanda_id, qq.question_text, qq.question_type,
                    o.option_text, aa.answer_text, aa.is_correct
               FROM quiz_attempts a
               JOIN quizzes q ON q.id = a.quiz_id
               JOIN modules m ON m.id = q.module_id
               JOIN courses c ON c.id = m.course_id
               LEFT JOIN quiz_attempt_answers aa ON aa.attempt_id = a.id
               LEFT JOIN quiz_questions qq ON qq.id = aa.question_id
               LEFT JOIN quiz_options o ON o.id = aa.selected_option_id
              WHERE a.user_id = :u
              ORDER BY a.attempted_at, a.id, qq.position, qq.id, o.position, aa.id', $userId) as $r) {
            $chiave = $r['tentativo'] . '-' . ($r['domanda_id'] ?? '0');
            $risposta = $r['option_text'] ?? $r['answer_text'] ?? '';

            if (isset($righe[$chiave])) {
                $righe[$chiave][7] .= self::SEP . $risposta;
                continue;
            }

            $corretta = null;
            if ($r['domanda_id'] !== null) {
                $corretta = $r['question_type'] === 'open' || $r['is_correct'] === null
                    ? 'Risposta aperta'
                    : ((int) $r['is_correct'] === 1 ? 'Sì' : 'No');
            }

            $righe[$chiave] = [
                $r['corso'], $r['modulo'], $r['questionario'], $r['attempted_at'], $r['score_pct'],
                (int) $r['passed'] === 1 ? 'Sì' : 'No',
                $r['question_text'], $r['domanda_id'] !== null ? $risposta : null, $corretta,
            ];
        }

        return self::foglio('Questionari', [
            'Corso', 'Modulo', 'Questionario', 'Tentativo del', 'Punteggio (%)', 'Superato',
            'Domanda', 'La tua risposta', 'Corretta',
        ], ['testo', 'testo', 'testo', 'data', 'numero', 'testo', 'testo', 'testo', 'testo'], array_values($righe));
    }

    /** Gli incontri dal vivo con un «Ci sarò», una presenza o un promemoria. */
    private static function incontri(int $userId): array
    {
        $righe = [];

        foreach (self::righe(
            'SELECT s.title, c.title AS corso, s.starts_at,
                    (SELECT MAX(r.created_at) FROM live_session_rsvps r WHERE r.session_id = s.id AND r.user_id = :u1) AS ci_saro,
                    (SELECT MAX(a.joined_at) FROM live_session_attendance a WHERE a.session_id = s.id AND a.user_id = :u2) AS entrato,
                    (SELECT MAX(a.source) FROM live_session_attendance a WHERE a.session_id = s.id AND a.user_id = :u3) AS origine,
                    (SELECT MAX(p.sent_at) FROM live_session_reminders p WHERE p.session_id = s.id AND p.user_id = :u4) AS promemoria
               FROM live_sessions s
               LEFT JOIN modules m ON m.id = s.module_id
               LEFT JOIN courses c ON c.id = m.course_id
              WHERE EXISTS (SELECT 1 FROM live_session_rsvps r WHERE r.session_id = s.id AND r.user_id = :u5)
                 OR EXISTS (SELECT 1 FROM live_session_attendance a WHERE a.session_id = s.id AND a.user_id = :u6)
                 OR EXISTS (SELECT 1 FROM live_session_reminders p WHERE p.session_id = s.id AND p.user_id = :u7)
              ORDER BY s.starts_at, s.id', $userId) as $r) {
            $presenza = null;

            if ($r['origine'] !== null) {
                $presenza = $r['origine'] === 'manual' ? 'Presente (segnato dal tutor)' : 'Presente';
            }

            $righe[] = [$r['title'], $r['corso'], $r['starts_at'], $r['promemoria'], $r['ci_saro'], $presenza, $r['entrato']];
        }

        return self::foglio('Incontri dal vivo', [
            'Incontro', 'Corso', 'Inizio', 'Promemoria ricevuto il', '«Ci sarò» il', 'Presenza', 'Entrato alle',
        ], ['testo', 'testo', 'data', 'data', 'data', 'testo', 'data'], $righe);
    }

    /**
     * Quello che lo studente ha scritto o scelto: giro del cerchio, Tre
     * parole, domande all'esperto, Bacheca. Una riga per contributo, in
     * ordine di data.
     */
    private static function scritti(int $userId): array
    {
        $righe = [];
        $visibile = static fn (?string $nascosto): string => $nascosto === null ? 'Visibile' : 'Nascosto da chi modera';
        $stati = ['pending' => 'In attesa', 'published' => 'Pubblicata', 'discarded' => 'Non pubblicata'];

        foreach (self::righe(
            'SELECT g.name, gq.question, ga.answer, ga.created_at, ga.updated_at, ga.hidden_at
               FROM group_answers ga
               JOIN group_questions gq ON gq.id = ga.question_id
               JOIN `groups` g ON g.id = gq.group_id
              WHERE ga.user_id = :u', $userId) as $r) {
            $righe[] = ['Giro del cerchio', $r['name'], $r['question'], $r['answer'],
                $visibile($r['hidden_at']), $r['created_at'], self::seCambiato($r['created_at'], $r['updated_at'])];
        }

        foreach (self::righe(
            'SELECT c.title, m.title AS modulo, w.word1, w.word2, w.word3, w.created_at
               FROM module_words w
               JOIN modules m ON m.id = w.module_id
               JOIN courses c ON c.id = w.course_id
              WHERE w.user_id = :u', $userId) as $r) {
            $parole = array_filter([$r['word1'], $r['word2'], $r['word3']], static fn ($p) => $p !== null && $p !== '');
            $righe[] = ['Tre parole', $r['title'], $r['modulo'], implode(', ', $parole), null, $r['created_at'], null];
        }

        foreach (self::righe(
            'SELECT c.title, m.title AS modulo, q.question, q.answer, q.status, q.created_at
               FROM course_questions q
               JOIN courses c ON c.id = q.course_id
               LEFT JOIN modules m ON m.id = q.module_id
              WHERE q.student_id = :u', $userId) as $r) {
            $testo = $r['question'] . ($r['answer'] !== null && $r['answer'] !== ''
                ? "\n\nRisposta dell'esperto: " . $r['answer'] : '');
            $righe[] = ["Domanda all'esperto", $r['title'], $r['modulo'] ?? 'Il corso in generale', $testo,
                $stati[$r['status']] ?? $r['status'], $r['created_at'], null];
        }

        foreach (self::righe(
            'SELECT c.title AS corso, r.title, r.kind, r.url, r.description, r.file_name, r.status, r.created_at
               FROM board_resources r JOIN courses c ON c.id = r.course_id
              WHERE r.author_id = :u', $userId) as $r) {
            $testo = implode("\n", array_filter([
                $r['description'],
                $r['url'] !== null && $r['url'] !== '' ? 'Link: ' . $r['url'] : null,
                $r['file_name'] !== null && $r['file_name'] !== '' ? 'File: ' . $r['file_name'] : null,
            ], static fn ($v) => $v !== null && $v !== ''));
            $righe[] = ['Bacheca, risorsa proposta', $r['corso'],
                (Bacheca::TIPI[$r['kind']] ?? $r['kind']) . ': ' . $r['title'], $testo,
                $stati[$r['status']] ?? $r['status'], $r['created_at'], null];
        }

        foreach (self::righe(
            'SELECT c.title AS corso, r.title, bu.body, bu.created_at, bu.updated_at, bu.hidden_at
               FROM board_uses bu
               JOIN board_resources r ON r.id = bu.resource_id
               JOIN courses c ON c.id = r.course_id
              WHERE bu.user_id = :u', $userId) as $r) {
            $righe[] = ['Bacheca, come l\'hai utilizzata', $r['corso'], $r['title'], $r['body'],
                $visibile($r['hidden_at']), $r['created_at'], self::seCambiato($r['created_at'], $r['updated_at'])];
        }

        foreach (self::righe(
            'SELECT c.title AS corso, r.title, s.created_at
               FROM board_saves s
               JOIN board_resources r ON r.id = s.resource_id
               JOIN courses c ON c.id = r.course_id
              WHERE s.user_id = :u', $userId) as $r) {
            $righe[] = ['Bacheca, risorsa salvata', $r['corso'], $r['title'], null, null, $r['created_at'], null];
        }

        usort($righe, static fn (array $a, array $b): int => strcmp((string) $a[5], (string) $b[5]));

        return self::foglio('Quello che hai scritto', [
            'Dove', 'Corso o gruppo', 'A cosa si riferisce', 'Testo', 'Stato', 'Data', 'Modificato il',
        ], ['testo', 'testo', 'testo', 'testo', 'testo', 'data', 'data'], $righe);
    }

    // ---------------------------------------------------------------

    /**
     * @param string[] $intestazione
     * @param array<int, string> $tipi
     * @param array<int, array<int, mixed>> $righe
     */
    private static function foglio(string $nome, array $intestazione, array $tipi, array $righe): array
    {
        return ['nome' => $nome, 'intestazione' => $intestazione, 'righe' => $righe, 'tipi' => $tipi];
    }

    /**
     * Esegue una query con lo studente in ogni segnaposto :u, :u1, :u2…
     *
     * @return array<int, array<string, mixed>>
     */
    private static function righe(string $sql, int $userId): array
    {
        preg_match_all('/:u\d*\b/', $sql, $trovati);
        $parametri = [];
        foreach (array_unique($trovati[0]) as $segnaposto) {
            $parametri[substr($segnaposto, 1)] = $userId;
        }

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($parametri);

        return $stmt->fetchAll();
    }

    /** «Modificato il» solo se e' davvero cambiato dopo la scrittura. */
    private static function seCambiato(?string $creato, ?string $aggiornato): ?string
    {
        return $aggiornato !== null && $aggiornato !== $creato ? $aggiornato : null;
    }

    private static function quando(?string $valore): string
    {
        return $valore === null || $valore === '' ? '' : date('d/m/Y H:i', (int) strtotime($valore));
    }

    private static function giorno(string $valore): string
    {
        return date('d/m/Y', (int) strtotime($valore));
    }
}
