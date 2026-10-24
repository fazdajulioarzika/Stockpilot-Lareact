<?php

namespace App\Exceptions;

use DomainException;

class WarehouseLockedException extends DomainException
{
    public function __construct(string $warehouseCode)
    {
        parent::__construct(
            "Gudang {$warehouseCode} sedang dalam stock opname. Transaksi stok ditolak sampai opname diposting atau dibatalkan."
        );
    }
}
