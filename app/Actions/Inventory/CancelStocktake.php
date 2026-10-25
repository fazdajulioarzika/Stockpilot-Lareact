<?php

namespace App\Actions\Inventory;

use App\Enums\StocktakeStatus;
use App\Models\Stocktake;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Models\AuditLog;

class CancelStocktake extends StocktakeAction
{
    /** Membatalkan juga melepas kunci gudang. Saldo tidak berubah. */
    public function execute(Stocktake $stocktake, User $user): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $user) {
            $s = $this->lockInStatus(
                $stocktake,
                StocktakeStatus::Draft,
                StocktakeStatus::Counting,
                StocktakeStatus::PendingApproval,
            );

            $s->forceFill(['status' => StocktakeStatus::Cancelled, 'completed_at' => now()])->save();
            AuditLog::record('stocktake.cancelled', $s, $user);
            return $s;
        });
    }
}
