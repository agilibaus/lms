<?php

declare(strict_types=1);

/**
 * Nessun caricamento accetta immagini SVG (17/10, Alessandro, per sicurezza),
 * senza database.
 *
 * Un SVG e' un documento, non una figura: puo' contenere uno script, e aperto
 * dal nostro dominio quello script girerebbe con la sessione di chi lo
 * guarda. Qui si controlla che non entri in nessun elenco di estensioni
 * ammesse, che nessun campo file lo proponga nella finestra di scelta, e che
 * le immagini siano verificate aprendole davvero (un SVG rinominato .png non
 * passa da getimagesize()).
 *
 * Esecuzione:  php tests/svg_test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Controllers\LessonController;
use App\Core\AvatarImage;
use App\Core\Bacheca;
use App\Core\CourseCover;
use App\Core\FileType;
use App\Core\GroupLogo;
use App\Core\TutorWelcome;

$ok = 0;
$fail = 0;

function check(string $label, bool $condition, string $dettaglio = ''): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;

    if (!$condition && $dettaglio !== '') {
        echo '         · ' . $dettaglio . PHP_EOL;
    }
}

$senzaSvg = static fn (array $estensioni): bool
    => array_intersect(array_map('strtolower', $estensioni), ['svg', 'svgz']) === [];

// ---------------------------------------------------------------
echo 'Gli elenchi delle estensioni ammesse' . PHP_EOL;

$elenchi = [
    'foto del profilo (AvatarImage)' => AvatarImage::EXTENSIONS,
    'copertina del corso (CourseCover)' => CourseCover::EXTENSIONS,
    'logo del gruppo (GroupLogo)' => GroupLogo::EXTENSIONS,
    'audio del benvenuto (TutorWelcome)' => TutorWelcome::AUDIO_EXTENSIONS,
    'file della bacheca (Bacheca)' => Bacheca::FILE_EXTENSIONS,
];

foreach (['IMAGE_EXTENSIONS' => 'immagini del testo della lezione', 'MATERIAL_EXTENSIONS' => 'materiali della lezione', 'VIDEO_EXTENSIONS' => 'video caricati'] as $costante => $etichetta) {
    $elenchi[$etichetta . ' (LessonController::' . $costante . ')'] = (array) (new ReflectionClassConstant(LessonController::class, $costante))->getValue();
}

foreach ($elenchi as $nome => $estensioni) {
    check($nome . ': niente SVG', $estensioni !== [] && $senzaSvg($estensioni), implode(', ', $estensioni));
}

// Ogni Upload::store() del codice passa da uno degli elenchi qui sopra: se
// ne nasce uno nuovo, questo test va aggiornato e lo dice.
$chiamate = 0;

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../app')) as $file) {
    if ($file->getExtension() === 'php') {
        $chiamate += substr_count((string) file_get_contents($file->getPathname()), 'Upload::store(');
    }
}

check('le chiamate a Upload::store() sono le otto conosciute (un caricamento nuovo va aggiunto qui)', $chiamate === 8, 'trovate ' . $chiamate);

check('l\'elenco dei tipi di file non conosce l\'SVG', FileType::label('svg') === 'SVG' && FileType::family('svg') === 'generic',
    FileType::label('svg') . ' / ' . FileType::family('svg'));

// ---------------------------------------------------------------
echo PHP_EOL . 'I campi file delle pagine' . PHP_EOL;

$campi = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../app/Views')) as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    preg_match_all('#<input[^>]*type="file"[^>]*>#', (string) file_get_contents($file->getPathname()), $trovati);

    foreach ($trovati[0] as $campo) {
        $campi[] = [basename(dirname($file->getPathname())) . '/' . $file->getFilename(), $campo];
    }
}

check('ci sono campi file da controllare', count($campi) >= 8, 'trovati ' . count($campi));

foreach ($campi as [$vista, $campo]) {
    preg_match('#accept="([^"]*)"#', $campo, $accept);
    $valore = strtolower($accept[1] ?? '');
    check($vista . ': ' . ($valore === '' ? 'nessun accept' : 'accept="' . $valore . '"') . ', che non propone SVG',
        !str_contains($valore, 'svg') && !str_contains($valore, 'image/*'));
}

// ---------------------------------------------------------------
echo PHP_EOL . 'Un SVG rinominato non passa per un\'immagine' . PHP_EOL;

$finto = sys_get_temp_dir() . '/svg_test_' . getmypid() . '.png';
file_put_contents($finto, '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>');
check('getimagesize() rifiuta un SVG chiamato .png', @getimagesize($finto) === false);
unlink($finto);

echo PHP_EOL . "Totale: {$ok} superati, {$fail} falliti" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
