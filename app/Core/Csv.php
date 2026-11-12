<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Export CSV in streaming, con BOM UTF-8 per la compatibilita' con Excel.
 */
class Csv
{
    /**
     * @param string[] $header
     * @param array<int, array<int, string|int|float|null>> $rows
     */
    /**
     * `$columnTypes` (06/11): come per l'XLSX, quali colonne sono date. Un
     * CSV e' solo testo e non puo' contenere date vere: escono come
     * «2026-09-16 18:00» («data») o «2026-09-16» («giorno»), che Excel
     * riconosce con qualunque lingua impostata, come gli altri programmi, e
     * che anche da testo si ordinano bene.
     *
     * @param array<int, string> $columnTypes
     */
    public static function send(string $fileName, array $header, array $rows, array $columnTypes = []): void
    {
        $rows = self::date($rows, $columnTypes);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");

        // Separatore ';' (atteso da Excel in locale italiano) e nessun carattere di
        // escape: l'argomento e' esplicito perche' da PHP 8.4 il default e' deprecato.
        fputcsv($out, array_map(self::cella(...), $header), ';', '"', '');

        foreach ($rows as $row) {
            fputcsv($out, array_map(self::cella(...), $row), ';', '"', '');
        }

        fclose($out);
        exit;
    }

    /**
     * Le colonne di date nel formato del CSV. Un valore che non e' una data
     * del database resta com'e'.
     *
     * @param array<int, array<int, mixed>> $rows
     * @param array<int, string> $columnTypes
     * @return array<int, array<int, mixed>>
     */
    public static function date(array $rows, array $columnTypes): array
    {
        foreach ($rows as $i => $row) {
            foreach ($columnTypes as $colonna => $tipo) {
                if (($tipo !== 'data' && $tipo !== 'giorno') || !isset($row[$colonna])) {
                    continue;
                }

                $valore = (string) $row[$colonna];

                if (preg_match('/^(\d{4}-\d{2}-\d{2})(?: (\d{2}:\d{2})(?::\d{2})?)?$/', $valore, $m) === 1) {
                    $rows[$i][$colonna] = $tipo === 'giorno' || !isset($m[2]) ? $m[1] : $m[1] . ' ' . $m[2];
                }
            }
        }

        return $rows;
    }

    /**
     * Un testo che comincia con `=`, `+`, `-` o `@` (o con una tabulazione o
     * un a capo, che Excel salta prima di guardare) e' una formula per Excel
     * e per LibreOffice, che la eseguono aprendo il file: un nome come
     * `=HYPERLINK("http://...";"clic")` diventava un collegamento nel foglio
     * dell'admin (13/10, verifica di sicurezza). Davanti si mette un
     * apostrofo, che lo fa leggere come testo; l'apostrofo resta visibile,
     * e in un nome vero queste iniziali non capitano.
     *
     * Restano come sono i valori fatti solo di cifre, spazi e punteggiatura
     * — «-3», «12,5», un telefono come «+39 333 1234567» —: senza lettere
     * una formula non chiama niente, e un telefono con l'apostrofo davanti
     * sarebbe solo brutto. Il foglio di calcolo (`Xlsx`) non ne ha bisogno:
     * scrive i testi come testo.
     */
    public static function cella(mixed $valore): mixed
    {
        if (!is_string($valore) || preg_match('/^[+-]?[0-9 .,()\/%-]*$/', $valore) === 1) {
            return $valore;
        }

        return preg_match('/^[=+\-@\t\r\n]/', $valore) === 1 ? "'" . $valore : $valore;
    }

    /**
     * Nome file "pulito" a partire da un titolo (es. "Corso Base" -> "corso-base").
     */
    public static function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';

        return trim($value, '-') ?: 'export';
    }
}
