<?php

declare(strict_types=1);

/**
 * Test di `App\Core\CorsiAPagamento` (06/11): il prezzo scritto nel modulo,
 * il link e il suo identificativo, i due impedimenti con le loro frasi e
 * l'interruttore generale.
 *
 * Le pagine e le azioni (il modulo del corso, il supervisore, i gruppi, il
 * catalogo) le prova `tests/pagamenti.js`.
 *
 * Esecuzione:  php tests/corsi_a_pagamento_test.php
 *
 * ATTENZIONE: scrive `PAYMENTS_ENABLED` nella tabella `settings` del database
 * del .env, e alla fine la rimette com'era, anche se un'asserzione fallisce.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';

use App\Core\CorsiAPagamento;
use App\Core\Settings;

$ok = 0;
$fail = 0;

function check(string $label, bool $condition): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
}

// --- Il prezzo -------------------------------------------------------------

foreach ([
    '49' => 4900,
    '49,00' => 4900,
    '49.00' => 4900,
    '49,5' => 4950,
    '49.5' => 4950,
    '0,99' => 99,
    '1.250,50' => 125050,
    '1.250' => 125000,
    '1250,50' => 125050,
    '49,00 €' => 4900,
    '€ 49' => 4900,
    ' 49 ' => 4900,
] as $testo => $atteso) {
    check("il prezzo «{$testo}» vale {$atteso} centesimi", CorsiAPagamento::prezzoDaTesto((string) $testo) === $atteso);
}

foreach (['', '0', '0,00', '-5', 'quarantanove', '49,999', '49,0,0', '1e3', '100000,00'] as $testo) {
    check("il prezzo «{$testo}» non si legge", CorsiAPagamento::prezzoDaTesto($testo) === null);
}

check('il prezzo massimo si legge, 99.999,99 €', CorsiAPagamento::prezzoDaTesto('99.999,99') === CorsiAPagamento::PREZZO_MASSIMO);
check('il prezzo si scrive «49,00 €»', CorsiAPagamento::prezzo(4900) === '49,00 €');
check('il prezzo si scrive «1.250,50 €»', CorsiAPagamento::prezzo(125050) === '1.250,50 €');
check('sul pulsante del catalogo, senza centesimi se non ci sono: «100 €», «1.250 €»',
    CorsiAPagamento::prezzoBreve(10000) === '100 €' && CorsiAPagamento::prezzoBreve(125000) === '1.250 €');
check('… e con i centesimi se ci sono: «49,50 €», «0,99 €»',
    CorsiAPagamento::prezzoBreve(4950) === '49,50 €' && CorsiAPagamento::prezzoBreve(99) === '0,99 €');
check('nel campo torna «49,00», e vuoto senza prezzo',
    CorsiAPagamento::prezzoNelCampo(4900) === '49,00' && CorsiAPagamento::prezzoNelCampo(null) === '');
check('quello che si rimette nel campo si rilegge uguale',
    CorsiAPagamento::prezzoDaTesto(CorsiAPagamento::prezzoNelCampo(125050)) === 125050);

// --- Link e identificativo -------------------------------------------------

check('il link di prova di Stripe va bene',
    CorsiAPagamento::linkValido('https://buy.stripe.com/test_cNicN46Yfg8m6s1cVd6J200'));
check('un link in http:// no', !CorsiAPagamento::linkValido('http://buy.stripe.com/test_cNicN46Yfg8m6s1cVd6J200'));
check('un testo che non è un indirizzo no', !CorsiAPagamento::linkValido('buy.stripe.com/abc'));
check('un javascript: no', !CorsiAPagamento::linkValido('javascript:alert(1)'));
check('un link oltre 255 caratteri no', !CorsiAPagamento::linkValido('https://buy.stripe.com/' . str_repeat('a', 240)));

check('«plink_1SOpk2KQ0J8hS5Rz» va bene', CorsiAPagamento::identificativoValido('plink_1SOpk2KQ0J8hS5Rz'));
foreach (['', 'plink_', 'PLINK_abc', 'price_123', 'plink_abc def', 'plink_abc-1', 'https://buy.stripe.com/x'] as $id) {
    check("l'identificativo «{$id}» no", !CorsiAPagamento::identificativoValido($id));
}

// --- Gli impedimenti -------------------------------------------------------

check('nessun gruppo e nessuna richiesta: niente impedisce', CorsiAPagamento::impedimento([], 0) === null);
check('un gruppo: la frase con «va rimosso dal gruppo»',
    CorsiAPagamento::impedimento(['Giallo'], 0)
    === 'Il corso è assegnato al gruppo Giallo: va rimosso dal gruppo prima di renderlo a pagamento.');
check('due gruppi: «ai gruppi Giallo e Verde», «va rimosso dai gruppi»',
    CorsiAPagamento::impedimento(['Giallo', 'Verde'], 0)
    === 'Il corso è assegnato ai gruppi Giallo e Verde: va rimosso dai gruppi prima di renderlo a pagamento.');
check('tre gruppi: «Blu, Giallo e Verde»',
    str_contains((string) CorsiAPagamento::impedimento(['Blu', 'Giallo', 'Verde'], 0), 'ai gruppi Blu, Giallo e Verde:'));
check('una richiesta: al singolare',
    CorsiAPagamento::impedimento([], 1)
    === 'C’è 1 richiesta di iscrizione in attesa: va decisa prima di rendere il corso a pagamento.');
check('due richieste: al plurale',
    CorsiAPagamento::impedimento([], 2)
    === 'Ci sono 2 richieste di iscrizione in attesa: vanno decise prima di rendere il corso a pagamento.');
check('con gruppi e richieste si dicono prima i gruppi',
    str_starts_with((string) CorsiAPagamento::impedimento(['Giallo'], 3), 'Il corso è assegnato al gruppo Giallo'));

// --- L'interruttore --------------------------------------------------------

$prima = Settings::stored(CorsiAPagamento::KEY);

try {
    check('la chiave si scrive dal pannello', Settings::isWritable(CorsiAPagamento::KEY));

    Settings::set(CorsiAPagamento::KEY, null);
    check('senza la riga i pagamenti sono spenti', !CorsiAPagamento::attivi());

    Settings::set(CorsiAPagamento::KEY, '0');
    check('«0»: spenti', !CorsiAPagamento::attivi());
    check('spenti: l\'admin non ha «A pagamento» su un corso che non lo è', !CorsiAPagamento::nelModulo(true, 'open'));
    check('spenti: un corso già a pagamento la tiene, anche per l\'admin', CorsiAPagamento::nelModulo(true, 'paid'));

    Settings::set(CorsiAPagamento::KEY, 'sì');
    check('acceso vale solo «1»', !CorsiAPagamento::attivi());

    Settings::set(CorsiAPagamento::KEY, '1');
    check('«1»: accesi', CorsiAPagamento::attivi());
    check('accesi: l\'admin ha «A pagamento»', CorsiAPagamento::nelModulo(true, 'open') && CorsiAPagamento::nelModulo(true, null));
    check('accesi: chi non è admin no, su un corso che non lo è', !CorsiAPagamento::nelModulo(false, 'closed'));
    check('accesi: chi non è admin la vede su un corso già a pagamento', CorsiAPagamento::nelModulo(false, 'paid'));
} finally {
    Settings::set(CorsiAPagamento::KEY, $prima);
}

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
