<?php

namespace App\Actions\Inventory;

use App\Enums\StocktakeStatus;
use App\Exceptions\InvalidStocktakeStateException;
use App\Models\Stocktake;

abstract class StocktakeAction
{
    /** Kunci baris opname. Request kedua menunggu, lalu melihat status yang sudah berubah. */
    protected function lockInStatus(Stocktake $stocktake, StocktakeStatus ...$allowed): Stocktake
    {
        $locked = Stocktake::query()->whereKey($stocktake->id)->lockForUpdate()->firstOrFail();

        if (!in_array($locked->status, $allowed, true)) {
            throw new InvalidStocktakeStateException($locked->status->value);
        }

        return $locked;
    }
}
