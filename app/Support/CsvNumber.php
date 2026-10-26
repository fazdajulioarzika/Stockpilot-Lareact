<?php

namespace App\Support;

/** Penanda sel angka agar tidak diperlakukan sebagai teks saat pengamanan rumus. */
final class CsvNumber
{
    public function __construct(public readonly string $value)
    {
    }
}
