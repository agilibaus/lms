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
    public static function send(string $fileName, array $header, array $rows): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");

        // Separatore ';' (atteso da Excel in locale italiano) e nessun carattere di
        // escape: l'argomento e' esplicito perche' da PHP 8.4 il default e' deprecato.
        fputcsv($out, $header, ';', '"', '');

        foreach ($rows as $row) {
            fputcsv($out, $row, ';', '"', '');
        }

        fclose($out);
        exit;
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
