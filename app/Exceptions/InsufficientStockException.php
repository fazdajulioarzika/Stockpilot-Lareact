<?php

namespace App\Exceptions;

use DomainException;

class InsufficientStockException extends DomainException
{
    public function __construct(string $productLabel, int $warehouseId)
    {
        parent::__construct("Stok tidak cukup untuk produk {$productLabel} di gudang ID {$warehouseId}.");
    }
}
