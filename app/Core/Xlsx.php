<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;
use ZipArchive;

/**
 * Export XLSX senza librerie esterne.
 *
 * Un file .xlsx e' un archivio zip con dentro qualche file XML. Scriverlo a
 * mano evita una dipendenza nuova: il progetto ne ha una sola, Dompdf, ed e'
 * una scelta presa (pistacchio-lms.md Sezione 1). Qui serve il minimo per un
 * foglio solo, con l'intestazione in grassetto, le date vere e le colonne
 * larghe quanto basta — non un generatore di fogli di calcolo.
 *
 * I testi sono scritti in linea (`t="inlineStr"`) invece che nella tabella
 * delle stringhe condivise: qualche byte in piu', un file in meno da tenere
 * allineato.
 *
 * Richiede l'estensione zip di PHP. Non e' fra i requisiti di composer.json
 * perche' un hosting che non ce l'ha deve poter installare lo stesso il resto:
 * chi chiama controlla prima con `disponibile()`.
 */
class Xlsx
{
    /** Le date di Excel contano i giorni dal 30/12/1899. */
    private const EXCEL_EPOCH = '1899-12-30 00:00:00';

    /** Larghezza massima di una colonna, in caratteri: oltre diventa scomoda. */
    private const MAX_WIDTH = 60;

    public static function disponibile(): bool
    {
        return extension_loaded('zip');
    }

    /**
     * @param string[] $header
     * @param array<int, array<int, string|int|float|null>> $rows
     * @param array<int, string> $columnTypes 'testo' (predefinito), 'numero' o 'data'.
     *                                        Le date arrivano come stringhe 'Y-m-d H:i:s'.
     */
    public static function send(
        string $fileName,
        array $header,
        array $rows,
        array $columnTypes = [],
        string $sheetName = 'Foglio1'
    ): void {
        $percorso = self::build($header, $rows, $columnTypes, $sheetName);

        if ($percorso === null) {
            http_response_code(500);
            echo 'Impossibile creare il file temporaneo per il foglio di calcolo.';
            exit;
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . filesize($percorso));
        header('X-Content-Type-Options: nosniff');
        readfile($percorso);
        @unlink($percorso);
        exit;
    }

    /**
     * Costruisce il file e ne restituisce il percorso temporaneo, oppure null
     * se non si e' potuto creare. Separata da `send()` perche' quella chiude
     * la risposta con `exit` e da un test non si puo' chiamare.
     *
     * Chi la usa direttamente e' responsabile di cancellare il file.
     *
     * @param string[] $header
     * @param array<int, array<int, string|int|float|null>> $rows
     * @param array<int, string> $columnTypes
     */
    public static function build(
        array $header,
        array $rows,
        array $columnTypes = [],
        string $sheetName = 'Foglio1'
    ): ?string {
        $percorso = tempnam(sys_get_temp_dir(), 'xlsx');

        if ($percorso === false) {
            return null;
        }

        $zip = new ZipArchive();
        $zip->open($percorso, ZipArchive::OVERWRITE | ZipArchive::CREATE);

        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::rels());
        $zip->addFromString('xl/workbook.xml', self::workbook($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($header, $rows, $columnTypes));

        $zip->close();

        return $percorso;
    }

    // ---------------------------------------------------------------
    // Foglio
    // ---------------------------------------------------------------

    /**
     * @param string[] $header
     * @param array<int, array<int, string|int|float|null>> $rows
     * @param array<int, string> $columnTypes
     */
    private static function sheet(array $header, array $rows, array $columnTypes): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            // L'ordine degli elementi non e' libero: `sheetViews` prima di
            // `cols`, `cols` prima di `sheetData`. Fuori ordine, Excel
            // considera il file danneggiato.
            //
            // La prima riga resta ferma scorrendo: con qualche centinaio di
            // utenti, senza, non si sa piu' quale colonna si sta leggendo.
            . '<sheetViews><sheetView workbookViewId="0">'
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>'
            . self::columns($header, $rows)
            . '<sheetData>';

        $xml .= '<row r="1">';

        foreach (array_values($header) as $colonna => $testo) {
            $xml .= '<c r="' . self::cell($colonna, 1) . '" s="1" t="inlineStr"><is><t>'
                . self::escape($testo) . '</t></is></c>';
        }

        $xml .= '</row>';

        $numeroRiga = 1;

        foreach ($rows as $row) {
            $numeroRiga++;
            $xml .= '<row r="' . $numeroRiga . '">';

            foreach (array_values($row) as $colonna => $valore) {
                $xml .= self::cellXml(
                    self::cell($colonna, $numeroRiga),
                    $valore,
                    $columnTypes[$colonna] ?? 'testo'
                );
            }

            $xml .= '</row>';
        }

        return $xml . '</sheetData></worksheet>';
    }

    /**
     * @param string|int|float|null $valore
     */
    private static function cellXml(string $riferimento, $valore, string $tipo): string
    {
        if ($valore === null || $valore === '') {
            return '';
        }

        if ($tipo === 'data') {
            $seriale = self::serial((string) $valore);

            if ($seriale !== null) {
                return '<c r="' . $riferimento . '" s="2"><v>' . $seriale . '</v></c>';
            }

            // Data illeggibile: si scrive com'e' invece di sparire, cosi'
            // l'errore si vede. Stessa regola dei segnaposto delle email
            // (pistacchio-lms.md Sezione 4).
            return '<c r="' . $riferimento . '" t="inlineStr"><is><t>'
                . self::escape((string) $valore) . '</t></is></c>';
        }

        if ($tipo === 'numero' && is_numeric($valore)) {
            return '<c r="' . $riferimento . '"><v>' . $valore . '</v></c>';
        }

        return '<c r="' . $riferimento . '" t="inlineStr"><is><t>'
            . self::escape((string) $valore) . '</t></is></c>';
    }

    /**
     * Giorni trascorsi dall'epoca di Excel, con la frazione per l'ora.
     *
     * Il calcolo e' fatto in UTC da entrambe le parti: non e' una conversione
     * di fuso, e' solo un modo per sottrarre due istanti senza che l'ora
     * legale sposti il risultato di un'ora.
     */
    private static function serial(string $dateTime): ?string
    {
        $data = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $dateTime,
            new DateTimeZone('UTC')
        );

        if ($data === false) {
            return null;
        }

        $epoca = new DateTimeImmutable(self::EXCEL_EPOCH, new DateTimeZone('UTC'));

        return rtrim(rtrim(sprintf(
            '%.10F',
            ($data->getTimestamp() - $epoca->getTimestamp()) / 86400
        ), '0'), '.');
    }

    /**
     * Larghezze: la voce piu' lunga della colonna, con un margine, fino a un
     * tetto. Excel non le calcola da solo all'apertura.
     *
     * @param string[] $header
     * @param array<int, array<int, string|int|float|null>> $rows
     */
    private static function columns(array $header, array $rows): string
    {
        $larghezze = [];

        foreach (array_values($header) as $colonna => $testo) {
            $larghezze[$colonna] = mb_strlen($testo);
        }

        foreach ($rows as $row) {
            foreach (array_values($row) as $colonna => $valore) {
                $lunghezza = mb_strlen((string) $valore);

                if ($lunghezza > ($larghezze[$colonna] ?? 0)) {
                    $larghezze[$colonna] = $lunghezza;
                }
            }
        }

        $xml = '<cols>';

        foreach ($larghezze as $colonna => $lunghezza) {
            $xml .= '<col min="' . ($colonna + 1) . '" max="' . ($colonna + 1)
                . '" width="' . min($lunghezza + 3, self::MAX_WIDTH) . '" customWidth="1"/>';
        }

        return $xml . '</cols>';
    }

    /**
     * Riferimento di cella: colonna 0, riga 1 -> "A1". Oltre la Z si passa a
     * due lettere, AA, AB e cosi' via.
     */
    private static function cell(int $colonna, int $riga): string
    {
        $lettere = '';

        for ($n = $colonna + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $lettere = chr(65 + (($n - 1) % 26)) . $lettere;
        }

        return $lettere . $riga;
    }

    private static function escape(string $valore): string
    {
        // I caratteri di controllo non sono ammessi in XML: un solo byte
        // sbagliato rende il file illeggibile a Excel, che non dice perche'.
        $valore = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $valore) ?? $valore;

        return htmlspecialchars($valore, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    // ---------------------------------------------------------------
    // Ossatura del file, sempre uguale
    // ---------------------------------------------------------------

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function rels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbook(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::escape(mb_substr($sheetName, 0, 31))
            . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    /**
     * Tre stili in tutto: 0 normale, 1 grassetto (intestazione), 2 data.
     * Gli elenchi di font, riempimenti e bordi devono esserci anche se vuoti,
     * altrimenti Excel considera il file danneggiato.
     */
    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy\ hh:mm"/></numFmts>'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '</cellXfs>'
            // Lo stile predefinito "Normal" va dichiarato: senza, i lettori
            // di fogli di calcolo segnalano un foglio privo di stile di base
            // e ne applicano uno loro.
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}
