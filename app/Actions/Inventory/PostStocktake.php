<?php

namespace App\Actions\Inventory;

use App\Enums\MovementType;
use App\Enums\StocktakeStatus;
use App\Models\InventoryStock;
use App\Models\Stocktake;
use App\Models\User;
use App\Services\StockService;
use DomainException;
use Illuminate\Support\Facades\DB;

class PostStocktake extends StocktakeAction
{
    public function __construct(private StockService $stock)
    {
    }

    /** Setujui dan posting penyesuaian. $approver diperiksa oleh policy, bukan di sini. */
    public function execute(Stocktake $stocktake, User $approver): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $approver) {
            $s = $this->lockInStatus($stocktake, StocktakeStatus::PendingApproval);

            $balances = InventoryStock::query()
                ->where('warehouse_id', $s->warehouse_id)
                ->lockForUpdate()
                ->get()
                ->mapWithKeys(fn ($row) => [$row->product_id => bcadd((string) $row->quantity, '0', 3)]);

            // Urut product_id agar urutan lock konsisten antar request (mencegah deadlock).
            $items = $s->items()->with('product:id,sku')->orderBy('product_id')->lockForUpdate()->get();

            foreach ($items as $item) {
                if ($item->counted_qty === null || $item->difference === null) {
                    throw new DomainException("Item {$item->product->sku} belum dihitung.");
                }

                // Gudang terkunci sejak snapshot, jadi saldo harus sama. Kalau tidak, ada yang janggal.
                $current = $balances->get($item->product_id, '0.000');

                if (bccomp($current, (string) $item->system_qty, 3) !== 0) {
                    throw new DomainException(
                        "Saldo {$item->product->sku} berubah sejak snapshot. Tolak opname ini dan hitung ulang."
                    );
                }

                $delta = (string) $item->difference;

                if (bccomp($delta, '0', 3) === 0) {
                    continue; // Tidak ada selisih, tidak ada movement.
                }

                $this->stock->apply(
                    $s->warehouse_id,
                    $item->product_id,
                    $delta,
                    MovementType::Adjustment,
                    $s,
                    $approver->id,
                );
            }

            $s->forceFill([
                'status' => StocktakeStatus::Posted,
                'approved_by' => $approver->id,
                'posted_at' => now(),
                'completed_at' => now(),
            ])->save();

            return $s;
        });
    }
}
