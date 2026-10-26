<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CsvExport
{
    /** ?format=intl: pemisah koma dan desimal titik. Default: titik koma dan desimal koma (Excel Indonesia). */
    public static function international(Request $request): bool
    {
        return $request->query('format') === 'intl';
    }

    public static function num(string|int|float|null $value): CsvNumber
    {
        return new CsvNumber((string) ($value ?? '0'));
    }

    /**
     * @param array<int, string> $headers
     * @param Closure(Closure(array): void): void $producer menerima fungsi $emit untuk menulis satu baris
     */
    public static function download(string $filename, array $headers, Closure $producer, bool $international): StreamedResponse
    {
        $delimiter = $international ? ',' : ';';

        return response()->streamDownload(function () use ($headers, $producer, $delimiter, $international) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8

            $emit = function (array $row) use ($out, $delimiter, $international): void {
                fputcsv($out, array_map(fn ($c) => self::cell($c, $international), $row), $delimiter, '"', '');
            };

            $emit($headers);
            $producer($emit);

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private static function cell(mixed $value, bool $international): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof CsvNumber) {
            $s = $value->value;

            if (str_contains($s, '.')) {
                $s = rtrim(rtrim($s, '0'), '.');
            }

            if ($s === '' || $s === '-' || $s === '-0') {
                $s = '0';
            }

            return $international ? $s : str_replace('.', ',', $s);
        }

        $text = (string) $value;

        // Cegah injeksi rumus spreadsheet pada teks yang berasal dari data pengguna.
        if ($text !== '' && preg_match('/^[=+\-@\t\r]/', $text) === 1 && !is_numeric($text)) {
            return "'" . $text;
        }

        return $text;
    }
}
