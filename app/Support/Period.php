<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Membaca periode dari query string dengan aman: tanggal salah tidak pernah menyebabkan error 500. */
final class Period
{
    public const MAX_DAYS = 366;

    /** Hanya format Y-m-d yang valid dan benar-benar ada di kalender (2026-02-30 ditolak). */
    public static function date(mixed $value): ?CarbonImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** @return array{from: CarbonImmutable, to: CarbonImmutable, error: string|null} */
    public static function resolve(Request $request, int $defaultDays = 30): array
    {
        $rawFrom = $request->query('from');
        $rawTo = $request->query('to');
        $from = self::date($rawFrom);
        $to = self::date($rawTo);
        $error = null;
        $today = CarbonImmutable::today();

        if ((filled($rawFrom) && !$from) || (filled($rawTo) && !$to)) {
            $error = 'Format tanggal tidak valid. Periode diatur ke default.';
            $from = $to = null;
        }

        $to ??= $today;
        $from ??= $to->subDays($defaultDays - 1);

        if ($from > $to) {
            $error = 'Tanggal "Dari" tidak boleh setelah "Sampai". Periode diatur ke default.';
            $to = $today;
            $from = $to->subDays($defaultDays - 1);
        }

        if ((int) $from->diffInDays($to) >= self::MAX_DAYS) {
            $error = 'Periode maksimal ' . self::MAX_DAYS . ' hari. Tanggal "Dari" disesuaikan.';
            $from = $to->subDays(self::MAX_DAYS - 1);
        }

        return ['from' => $from, 'to' => $to, 'error' => $error];
    }
}
