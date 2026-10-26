<?php

namespace App\Support;

/** Aritmetika uang dengan bcmath: 4 desimal, pembulatan setengah ke atas (nilai tidak negatif). */
final class Money
{
    public static function round4(string $value): string
    {
        return bcadd($value, '0.00005', 4);
    }

    public static function normalize(string $value): string
    {
        $n = bcadd($value, '0', 4);

        return bccomp($n, '0', 4) === 0 ? '0.0000' : $n;
    }
}
