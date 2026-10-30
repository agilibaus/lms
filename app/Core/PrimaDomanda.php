<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\QuizOptionModel;
use App\Models\QuizQuestionModel;

/**
 * Un questionario a domande nasce con la sua prima domanda (30/10,
 * Alessandro): «Nuovo questionario» e «Nuova verifica finale» la chiedono
 * nella stessa pagina, e senza non si salva niente. Cosi' nessuno studente
 * puo' trovare un questionario vuoto, e nessuno resta fermo davanti a un
 * questionario che non si puo' superare.
 *
 * I campi della domanda sono gli stessi di «Nuova domanda»
 * (`quizzes/_question_fields.php`), e la lettura dal POST e' la stessa
 * (`QuizController::domandaDalPost`). Qui c'e' quello che serve in piu'
 * a una pagina di creazione: se il salvataggio si rifiuta (una domanda
 * senza la risposta corretta, un titolo vuoto), la pagina torna con tutto
 * quello che era stato scritto, domanda compresa, una volta sola.
 */
final class PrimaDomanda
{
    private const RIPRENDI = 'prima_domanda_da_riprendere';

    /**
     * Salva la domanda gia' letta e controllata nel questionario appena
     * creato. Chi chiama tiene tutto in una transazione: o il questionario
     * nasce con la domanda, o non nasce.
     *
     * @param array{0: string, 1: string, 2: array<int, array{text: string, is_correct: bool}>} $domanda
     */
    public static function salva(int $quizId, array $domanda): void
    {
        [$testo, $tipo, $opzioni] = $domanda;
        $questionId = QuizQuestionModel::create($quizId, $testo, $tipo);
        QuizOptionModel::replaceForQuestion($questionId, $opzioni);
    }

    /**
     * Ricorda la pagina rifiutata: `$campi` sono gli altri campi della
     * pagina (titoli, soglia), la domanda si prende dal POST com'e' arrivata.
     * `$chi` e `$id` dicono di quale pagina si tratta (il modulo del
     * questionario, o il corso della verifica finale).
     *
     * @param array<string, mixed> $campi
     */
    public static function ricorda(string $chi, int $id, array $campi): void
    {
        $_SESSION[self::RIPRENDI] = [
            'chi' => $chi,
            'id' => $id,
            'campi' => $campi,
            'domanda' => [
                'question_text' => (string) ($_POST['question_text'] ?? ''),
                'question_type' => (string) ($_POST['question_type'] ?? 'single_choice'),
                'options' => is_array($_POST['options'] ?? null) ? array_values(array_map('strval', $_POST['options'])) : [],
                'correct_option' => (int) ($_POST['correct_option'] ?? -1),
                'correct_options' => is_array($_POST['correct_options'] ?? null) ? array_map('intval', $_POST['correct_options']) : [],
            ],
        ];
    }

    /**
     * La pagina rifiutata da riproporre, una volta sola, o null.
     *
     * @return array{campi: array<string, mixed>, question: array{question_text: string, question_type: string}, options: list<array{option_text: string, is_correct: int}>}|null
     */
    public static function riprendi(string $chi, int $id): ?array
    {
        $salvato = $_SESSION[self::RIPRENDI] ?? null;
        unset($_SESSION[self::RIPRENDI]);

        if (!is_array($salvato) || ($salvato['chi'] ?? '') !== $chi || (int) ($salvato['id'] ?? -1) !== $id
            || !is_array($salvato['campi'] ?? null) || !is_array($salvato['domanda'] ?? null)) {
            return null;
        }

        $d = $salvato['domanda'];
        $tipo = QuizScoring::esiste((string) $d['question_type']) ? (string) $d['question_type'] : 'single_choice';

        // Le righe com'erano nella pagina, vuote comprese: la risposta
        // corretta e' segnata sul numero della riga.
        $opzioni = [];

        if ($tipo === 'true_false') {
            foreach (['Vero', 'Falso'] as $i => $parola) {
                $opzioni[] = ['option_text' => $parola, 'is_correct' => (int) ($d['correct_option'] === $i)];
            }
        } elseif ($tipo !== 'open') {
            foreach ($d['options'] as $i => $testo) {
                $giusta = $tipo === 'multiple_choice'
                    ? in_array($i, $d['correct_options'], true)
                    : $d['correct_option'] === $i;
                $opzioni[] = ['option_text' => $testo, 'is_correct' => (int) $giusta];
            }
        }

        return [
            'campi' => $salvato['campi'],
            'question' => ['question_text' => (string) $d['question_text'], 'question_type' => $tipo],
            'options' => $opzioni,
        ];
    }
}
