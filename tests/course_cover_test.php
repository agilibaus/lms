<?php

declare(strict_types=1);

/**
 * Test della copertina del corso: ritaglio 16:9, due misure, percorsi,
 * testo alternativo e riquadro con le iniziali.
 *
 * Esecuzione:  php tests/course_cover_test.php
 *
 * Le immagini di prova vengono generate con GD e scritte sotto
 * storage/course-covers/, in cartelle con identificativi altissimi che nessun
 * corso vero ha; alla fine vengono rimosse.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\CourseCover;
use App\Core\Upload;

$ok = 0;
$fail = 0;

function check(string $label, bool $condition): void
{
    global $ok, $fail;
    $condition ? $ok++ : $fail++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
}

if (!extension_loaded('gd')) {
    echo 'GD non disponibile: i test sulle immagini non possono girare.' . PHP_EOL;
    exit(1);
}

/** Genera un file immagine di prova e ne restituisce il percorso. */
function sampleImage(int $width, int $height, string $format = 'jpeg'): string
{
    $image = imagecreatetruecolor($width, $height);

    // Due bande di colore: servono a verificare che il ritaglio prenda il
    // centro e non un angolo.
    imagefilledrectangle($image, 0, 0, $width, (int) ($height / 2), imagecolorallocate($image, 200, 30, 30));
    imagefilledrectangle($image, 0, (int) ($height / 2), $width, $height, imagecolorallocate($image, 30, 30, 200));

    $path = sys_get_temp_dir() . '/cover-test-' . bin2hex(random_bytes(6)) . '.' . $format;

    $format === 'png' ? imagepng($image, $path) : imagejpeg($image, $path, 90);
    imagedestroy($image);

    return $path;
}

/** Dimensioni di un file salvato sotto /storage. */
function storedSize(string $relative): array
{
    $info = getimagesize(Upload::absolutePath($relative));

    return $info === false ? [0, 0] : [(int) $info[0], (int) $info[1]];
}

$created = [];

// ---------------------------------------------------------------
echo 'Percorsi delle due misure' . PHP_EOL;

check(
    'la misura piccola aggiunge -card prima dell\'estensione',
    CourseCover::cardPath('course-covers/7/abcdef.jpg') === 'course-covers/7/abcdef-card.jpg'
);
check(
    'un percorso senza estensione riceve comunque .jpg',
    CourseCover::cardPath('course-covers/7/abcdef') === 'course-covers/7/abcdef-card.jpg'
);
check(
    'un punto nella cartella non confonde il calcolo',
    CourseCover::cardPath('course-covers/7/a.b/c.png') === 'course-covers/7/a.b/c-card.png'
);

// ---------------------------------------------------------------
echo PHP_EOL . 'Ritaglio e riduzione' . PHP_EOL;

$courseId = 999001;
$source = sampleImage(2000, 1000);
$wide = CourseCover::render($source, $courseId);
$created[] = $wide;
unlink($source);

[$w, $h] = storedSize($wide);
check('la misura grande e\' larga 1280 px', $w === 1280);
check('la misura grande e\' alta 720 px (16:9)', $h === 720);

$card = CourseCover::cardPath($wide);
check('la misura piccola esiste sul disco', is_file(Upload::absolutePath($card)));

[$cw, $ch] = storedSize($card);
check('la misura piccola e\' larga 640 px', $cw === 640);
check('la misura piccola e\' alta 360 px (16:9)', $ch === 360);
check('il percorso restituito sta sotto course-covers/{id}', str_starts_with($wide, 'course-covers/' . $courseId . '/'));
check('il file salvato e\' un JPEG', str_ends_with($wide, '.jpg'));

// ---------------------------------------------------------------
echo PHP_EOL . 'Immagini di proporzioni diverse' . PHP_EOL;

$courseId2 = 999002;
$source = sampleImage(800, 1600); // verticale, tipica foto da telefono
$tall = CourseCover::render($source, $courseId2);
$created[] = $tall;
unlink($source);

[$w, $h] = storedSize($tall);
check('da un\'immagine verticale esce comunque 16:9', abs(($w / $h) - (16 / 9)) < 0.02);
check('una verticale stretta non viene ingrandita oltre la sua larghezza', $w <= 800);

$courseId3 = 999003;
$source = sampleImage(320, 180); // gia' piccola
$small = CourseCover::render($source, $courseId3);
$created[] = $small;
unlink($source);

[$w, $h] = storedSize($small);
check('un\'immagine piccola non viene ingrandita', $w === 320 && $h === 180);
check(
    'la misura piccola di un\'immagine gia\' piccola resta uguale',
    storedSize(CourseCover::cardPath($small)) === [320, 180]
);

// ---------------------------------------------------------------
echo PHP_EOL . 'Formati e file non validi' . PHP_EOL;

$courseId4 = 999004;
$source = sampleImage(1600, 900, 'png');
$fromPng = CourseCover::render($source, $courseId4);
$created[] = $fromPng;
unlink($source);
check('un PNG viene accettato e convertito in JPEG', str_ends_with($fromPng, '.jpg') && is_file(Upload::absolutePath($fromPng)));

$notAnImage = sys_get_temp_dir() . '/cover-test-' . bin2hex(random_bytes(6)) . '.jpg';
file_put_contents($notAnImage, 'questo non e\' un\'immagine');

$rejected = false;

try {
    CourseCover::render($notAnImage, 999005);
} catch (\RuntimeException $e) {
    $rejected = true;
}

unlink($notAnImage);
check('un file che non e\' un\'immagine viene rifiutato', $rejected);
check(
    'il rifiuto non lascia cartelle a meta\'',
    !is_dir(Upload::absolutePath('course-covers/999005'))
);

// ---------------------------------------------------------------
echo PHP_EOL . 'Scelta della misura da servire' . PHP_EOL;

check('con size piccola si serve la misura piccola', CourseCover::pathFor($wide, true) === CourseCover::cardPath($wide));
check('senza size piccola si serve la misura grande', CourseCover::pathFor($wide, false) === $wide);

// Copertina salvata senza GD: esiste solo la misura grande.
$lonely = 'course-covers/999006/solo.jpg';
@mkdir(Upload::absolutePath('course-covers/999006'), 0775, true);
copy(Upload::absolutePath($wide), Upload::absolutePath($lonely));
$created[] = $lonely;

check('senza la misura piccola si ripiega sulla grande', CourseCover::pathFor($lonely, true) === $lonely);
check('un percorso inesistente non viene servito', CourseCover::pathFor('course-covers/999006/mai-esistita.jpg', false) === null);

// ---------------------------------------------------------------
echo PHP_EOL . 'Eliminazione' . PHP_EOL;

CourseCover::delete($wide);
check('l\'eliminazione rimuove la misura grande', !is_file(Upload::absolutePath($wide)));
check('l\'eliminazione rimuove anche la misura piccola', !is_file(Upload::absolutePath(CourseCover::cardPath($wide))));

$deletedTwice = true;

try {
    CourseCover::delete($wide);
    CourseCover::delete(null);
    CourseCover::delete('');
} catch (\Throwable $e) {
    $deletedTwice = false;
}

check('eliminare due volte, o il nulla, non solleva errori', $deletedTwice);

// ---------------------------------------------------------------
echo PHP_EOL . 'Indirizzi esterni (valori gia\' in tabella prima di questa modifica)' . PHP_EOL;

check('http:// riconosciuto come esterno', CourseCover::isExternalUrl('http://esempio.it/a.jpg'));
check('https:// riconosciuto come esterno', CourseCover::isExternalUrl('https://esempio.it/a.jpg'));
check('un percorso di storage non e\' esterno', !CourseCover::isExternalUrl('course-covers/7/a.jpg'));
check(
    'un indirizzo esterno viene usato tale e quale',
    CourseCover::url(['id' => 7, 'cover_image' => 'https://esempio.it/a.jpg']) === 'https://esempio.it/a.jpg'
);
check(
    'un percorso di storage diventa la rotta della misura piccola',
    CourseCover::url(['id' => 7, 'cover_image' => 'course-covers/7/a.jpg']) === '/corsi/7/copertina/piccola'
);
check(
    'la misura grande usa la rotta senza suffisso',
    CourseCover::url(['id' => 7, 'cover_image' => 'course-covers/7/a.jpg'], false) === '/corsi/7/copertina'
);
check('senza copertina non c\'e\' indirizzo', CourseCover::url(['id' => 7, 'cover_image' => null]) === null);
check('copertina vuota equivale ad assente', CourseCover::url(['id' => 7, 'cover_image' => '']) === null);

// ---------------------------------------------------------------
echo PHP_EOL . 'Testo alternativo' . PHP_EOL;

check('spazi in eccesso ridotti', CourseCover::normalizeAlt("  Due   persone\n in cerchio ") === 'Due persone in cerchio');
check('testo vuoto diventa null', CourseCover::normalizeAlt('   ') === null);
check('null resta null', CourseCover::normalizeAlt(null) === null);
check('il testo viene troncato a 255 caratteri', mb_strlen((string) CourseCover::normalizeAlt(str_repeat('a', 400))) === 255);
check(
    'accenti contati come un carattere solo, non troncati a meta\'',
    mb_strlen((string) CourseCover::normalizeAlt(str_repeat('è', 400))) === 255
);
check(
    'in vista si usa il testo scritto',
    CourseCover::altFor(['title' => 'Yoga', 'cover_alt' => 'Tappetini stesi']) === 'Tappetini stesi'
);
check(
    'senza testo alternativo si ripiega sul titolo',
    CourseCover::altFor(['title' => 'Yoga', 'cover_alt' => null]) === 'Yoga'
);
check(
    'un testo di soli spazi equivale ad assente',
    CourseCover::altFor(['title' => 'Yoga', 'cover_alt' => '   ']) === 'Yoga'
);

// ---------------------------------------------------------------
echo PHP_EOL . 'Riquadro con le iniziali' . PHP_EOL;

check('due parole danno due iniziali', CourseCover::initials('Mindfulness Avanzata') === 'MA');
check('una parola sola da\' una iniziale', CourseCover::initials('Yoga') === 'Y');
check('gli articoli brevi vengono saltati', CourseCover::initials('Il corso di Yoga') === 'CY');
check('una parola composta da\' una sola iniziale', CourseCover::initials('Auto-aiuto pratico') === 'AP');
check('un titolo di sole parole brevi usa comunque le sue iniziali', CourseCover::initials('Io e te') === 'IE');
check('i numeri iniziali non diventano iniziali', CourseCover::initials('2026 Meditazione Guidata') === 'MG');
check('un titolo vuoto non lascia il riquadro muto', CourseCover::initials('   ') === '?');
check('un titolo di soli simboli ripiega sul punto interrogativo', CourseCover::initials('### ***') === '?');
check('le iniziali sono maiuscole', CourseCover::initials('yoga dolce') === 'YD');
check('gli accenti non rompono le iniziali', CourseCover::initials('Équilibre Interiore') === 'ÉI');

check('la tinta sta nell\'arco dei gradi', CourseCover::hue(12) >= 0 && CourseCover::hue(12) < 360);
check('la tinta e\' stabile per lo stesso corso', CourseCover::hue(12) === CourseCover::hue(12));
check('corsi consecutivi hanno tinte diverse', CourseCover::hue(12) !== CourseCover::hue(13));

// ---------------------------------------------------------------
echo PHP_EOL . 'Tipo MIME' . PHP_EOL;

check('jpg servito come image/jpeg', CourseCover::mimeFor('a/b.jpg') === 'image/jpeg');
check('png servito come image/png', CourseCover::mimeFor('a/b.png') === 'image/png');
check('webp servito come image/webp', CourseCover::mimeFor('a/b.webp') === 'image/webp');
check('estensione ignota servita come image/jpeg', CourseCover::mimeFor('a/b') === 'image/jpeg');

// ---------------------------------------------------------------
// Pulizia: restano solo le cartelle di prova, con identificativi che nessun
// corso vero ha.
foreach ($created as $relative) {
    CourseCover::delete($relative);
}

// Si svuota la cartella invece di togliere solo i file di questa esecuzione:
// un test interrotto a meta' lascerebbe altrimenti file con nomi casuali che
// nessuna esecuzione successiva sa piu' di dover rimuovere.
foreach ([999001, 999002, 999003, 999004, 999005, 999006] as $id) {
    $dir = Upload::absolutePath('course-covers/' . $id);

    if (!is_dir($dir)) {
        continue;
    }

    foreach (glob($dir . '/*') ?: [] as $leftover) {
        @unlink($leftover);
    }

    @rmdir($dir);
}

echo PHP_EOL . "Totale: $ok superati, $fail falliti" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
