<?php

declare(strict_types=1);

/**
 * Test della correzione dei quiz.
 *
 * Esecuzione:  php tests/quiz_scoring_test.php
 *
 * Nessun database e nessun browser: e' aritmetica su dati noti. Ed e' la
 * parte dove un errore fa il danno peggiore, perche' un punteggio sbagliato
 * non si vede — si crede.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\QuizScoring;

$ok = 0;
$fail = 0;

function check(string $label, bool $condition): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
}

echo PHP_EOL . '--- I tipi di domanda' . PHP_EOL;

foreach (['single_choice', 'multiple_choice', 'true_false', 'open'] as $tipo) {
    check('«' . $tipo . '» esiste', QuizScoring::esiste($tipo));
}

check('un tipo inventato non esiste', !QuizScoring::esiste('saggio_breve'));

check('la scelta singola fa punteggio', QuizScoring::valutata('single_choice'));
check('la scelta multipla fa punteggio', QuizScoring::valutata('multiple_choice'));
check('il vero/falso fa punteggio', QuizScoring::valutata('true_false'));
check('l\'aperta NON fa punteggio', !QuizScoring::valutata('open'));

check('solo la multipla accetta piu\' risposte', QuizScoring::piuRisposte('multiple_choice'));
check('la singola no', !QuizScoring::piuRisposte('single_choice'));
check('l\'aperta non ha opzioni', !QuizScoring::haOpzioni('open'));
check('le altre si\'', QuizScoring::haOpzioni('true_false'));

echo PHP_EOL . '--- Scelta multipla: tutto o niente' . PHP_EOL;

check('esattamente le corrette: giusta', QuizScoring::multiplaGiusta([1, 3], [1, 3]));
check('in ordine diverso: giusta lo stesso', QuizScoring::multiplaGiusta([3, 1], [1, 3]));
check('una sola su due: sbagliata', !QuizScoring::multiplaGiusta([1], [1, 3]));
check('le due giuste piu\' una sbagliata: sbagliata', !QuizScoring::multiplaGiusta([1, 3, 5], [1, 3]));
check('tutte sbagliate: sbagliata', !QuizScoring::multiplaGiusta([2, 4], [1, 3]));
check('nessuna selezionata: sbagliata', !QuizScoring::multiplaGiusta([], [1, 3]));
check('una sola corretta, indovinata', QuizScoring::multiplaGiusta([7], [7]));
check('tre su tre', QuizScoring::multiplaGiusta([1, 2, 3], [3, 2, 1]));
check('tre su quattro non basta', !QuizScoring::multiplaGiusta([1, 2, 3], [1, 2, 3, 4]));

// Un doppione nell'invio non deve cambiare l'esito: il browser non dovrebbe
// mandarlo, ma il corpo della richiesta lo scrive il client.
check('un doppione non cambia l\'esito', QuizScoring::multiplaGiusta([1, 1, 3], [1, 3]));

// Domanda mal costruita: nessuna opzione corretta. Non si regala il punto a
// chi non seleziona niente.
check('senza corrette, non selezionare niente non vale', !QuizScoring::multiplaGiusta([], []));
check('senza corrette, selezionare qualcosa non vale', !QuizScoring::multiplaGiusta([1], []));

echo PHP_EOL . '--- Il punteggio' . PHP_EOL;

check('tutte giuste fa 100', QuizScoring::percentuale(4, 4) === 100.0);
check('meta\' fa 50', QuizScoring::percentuale(2, 4) === 50.0);
check('nessuna fa 0', QuizScoring::percentuale(0, 4) === 0.0);
check('due su tre fa 66,67', QuizScoring::percentuale(2, 3) === 66.67);
check('non si sfora il 100', QuizScoring::percentuale(9, 4) === 100.0);
check('le giuste negative valgono zero', QuizScoring::percentuale(-3, 4) === 0.0);

// Il caso limite: un quiz di sole domande aperte non ha niente da correggere.
check('senza domande valutate il punteggio e\' pieno', QuizScoring::percentuale(0, 0) === 100.0);

echo PHP_EOL . '--- Superato o no' . PHP_EOL;

check('sopra la soglia: superato', QuizScoring::superato(80.0, 70, 5));
check('esattamente la soglia: superato', QuizScoring::superato(70.0, 70, 5));
check('sotto la soglia: non superato', !QuizScoring::superato(69.99, 70, 5));
check('soglia 100 e punteggio pieno: superato', QuizScoring::superato(100.0, 100, 5));
check('soglia 100 e una sbagliata: non superato', !QuizScoring::superato(80.0, 100, 5));

// Un quiz di sole aperte si consegna, non si supera. Se risultasse non
// superato, con «quiz obbligatorio» terrebbe chiusi per sempre i moduli
// successivi: e' la ragione per cui questo caso e' esplicito.
check('quiz di sole aperte: sempre superato', QuizScoring::superato(100.0, 70, 0));
check('…anche con la soglia a 100', QuizScoring::superato(100.0, 100, 0));

echo PHP_EOL . '--- Quante domande fanno punteggio' . PHP_EOL;

$misto = [
    ['question_type' => 'single_choice'],
    ['question_type' => 'open'],
    ['question_type' => 'multiple_choice'],
    ['question_type' => 'open'],
    ['question_type' => 'true_false'],
];

check('in un quiz misto si contano solo le valutate', QuizScoring::conteggioValutate($misto) === 3);
check('un quiz di sole aperte ne conta zero', QuizScoring::conteggioValutate([
    ['question_type' => 'open'],
    ['question_type' => 'open'],
]) === 0);
check('un quiz vuoto ne conta zero', QuizScoring::conteggioValutate([]) === 0);
check('senza tipo vale la scelta singola', QuizScoring::conteggioValutate([[]]) === 1);

echo PHP_EOL . '--- Un quiz misto, dall\'inizio alla fine' . PHP_EOL;

/*
 * Cinque domande: tre valutate e due aperte. Lo studente indovina due delle
 * tre valutate e scrive qualcosa nelle aperte. Il punteggio deve essere
 * 66,67 — due su tre — e non 40, che sarebbe due su cinque: e' l'errore che
 * le aperte non devono poter introdurre.
 */
$valutate = QuizScoring::conteggioValutate($misto);
$punteggio = QuizScoring::percentuale(2, $valutate);

check('le aperte non entrano nel denominatore', $punteggio === 66.67);
check('con soglia 60 e\' superato', QuizScoring::superato($punteggio, 60, $valutate));
check('con soglia 70 non lo e\'', !QuizScoring::superato($punteggio, 70, $valutate));

echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
