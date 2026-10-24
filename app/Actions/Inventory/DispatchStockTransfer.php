<?php

namespace App\Actions\Inventory;

use App\Enums\MovementType;
use App\Enums\TransferStatus;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;

class DispatchStockTransfer extends TransferAction
{
    public function __construct(private StockService $stock)
    {
    }

    public function execute(StockTransfer $transfer, User $user): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $user) {
            $t = $this->lockInStatus($transfer, TransferStatus::Approved);

            // Urut product_id agar urutan lock konsisten antar request (mencegah deadlock).
            $items = $t->items()->with('product:id,sku,is_active')->orderBy('product_id')->lockForUpdate()->get();
            $this->assertUsable($t, $items);

            foreach ($items as $item) {
                // Stok kurang: exception dilempar dan seluruh transaksi di-rollback.
                $this->stock->apply(
                    $t->source_warehouse_id,
                    $item->product_id,
                    bcmul((string) $item->quantity, '-1', 3),
                    MovementType::TransferOut,
                    $t,
                    $user->id,
                );

                $item->forceFill(['qty_sent' => $item->quantity])->save();
            }

            $t->forceFill([
                'status' => TransferStatus::InTransit,
                'dispatched_by' => $user->id,
                'dispatched_at' => now(),
            ])->save();

            return $t;
        });
    }
}
