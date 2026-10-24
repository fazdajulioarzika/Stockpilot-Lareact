<?php

namespace App\Enums;

enum StocktakeStatus: string
{
    case Draft = 'draft';
    case Counting = 'counting';
    case PendingApproval = 'pending_approval';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Counting => 'Sedang dihitung',
            self::PendingApproval => 'Menunggu approval',
            self::Posted => 'Diposting',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /** Status yang mengunci transaksi stok di gudangnya. */
    public function locksWarehouse(): bool
    {
        return in_array($this, self::locking(), true);
    }

    /** @return array<self> */
    public static function locking(): array
    {
        return [self::Counting, self::PendingApproval];
    }
}
