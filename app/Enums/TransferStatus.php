<?php

namespace App\Enums;

enum TransferStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case InTransit = 'in_transit';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Menunggu approval',
            self::Approved => 'Disetujui',
            self::InTransit => 'Dalam perjalanan',
            self::PartiallyReceived => 'Diterima sebagian',
            self::Received => 'Diterima',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function hasStockInTransit(): bool
    {
        return in_array($this, [self::InTransit, self::PartiallyReceived], true);
    }
}
