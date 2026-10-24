<?php

namespace App\Actions\Inventory;

use App\Enums\StocktakeStatus;
use App\Models\Stocktake;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class SubmitStocktakeForApproval extends StocktakeAction
{
    public function execute(Stocktake $stocktake, User $user): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $user) {
            $s = $this->lockInStatus($stocktake, StocktakeStatus::Counting);

            $items = $s->items()->with('product:id,sku')->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw new DomainException('Opname belum memiliki item.');
            }

            $uncounted = $items->whereNull('counted_qty')->count();

            if ($uncounted > 0) {
                throw new DomainException("Masih ada {$uncounted} item yang belum dihitung.");
            }

            foreach ($items as $item) {
                $hasDifference = bccomp((string) $item->difference, '0', 3) !== 0;

                if ($hasDifference && blank($item->reason)) {
                    throw new DomainException("Item {$item->product->sku} memiliki selisih, alasan wajib diisi.");
                }
            }

            $s->forceFill([
                'status' => StocktakeStatus::PendingApproval,
                'submitted_at' => now(),
                'submitted_by' => $user->id,
                'rejection_note' => null,
            ])->save();

            return $s;
        });
    }
}
