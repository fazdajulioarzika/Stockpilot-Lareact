<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Diposting',
        };
    }
}
