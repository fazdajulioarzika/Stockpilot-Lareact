<?php

namespace App\Actions\Inventory;

use App\Enums\StocktakeStatus;
use App\Models\InventoryStock;
use App\Models\Stocktake;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Support\Facades\DB;

class StartStocktake extends StocktakeAction
{
    public function execute(Stocktake $stocktake, User $user): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $user) {
            $s = $this->lockInStatus($stocktake, StocktakeStatus::Draft);

            // Kunci eksklusif gudang: menunggu semua posting yang sedang berjalan selesai,
            // dan menyerialisasi dua permintaan "mulai opname" di gudang yang sama.
            $warehouse = Warehouse::query()->whereKey($s->warehouse_id)->lockForUpdate()->firstOrFail();

            if (!$warehouse->is_active) {
                throw new DomainException('Gudang tidak aktif.');
            }

            $other = Stocktake::query()
                ->where('warehouse_id', $s->warehouse_id)
                ->whereKeyNot($s->id)
                ->whereIn('status', array_map(fn (StocktakeStatus $x) => $x->value, StocktakeStatus::locking()))
                ->lockForUpdate()
                ->first(['id', 'number']);

            if ($other) {
                throw new DomainException("Gudang {$warehouse->code} sudah memiliki opname aktif ({$other->number}).");
            }

            // Snapshot saldo. Tidak ada posting yang bisa berjalan di antara baris ini dan commit.
            $balances = InventoryStock::query()
                ->where('warehouse_id', $s->warehouse_id)
                ->lockForUpdate()
                ->get()
                ->mapWithKeys(fn ($row) => [$row->product_id => bcadd((string) $row->quantity, '0', 3)]);

            // Produk tambahan dari draft (mungkin saldo 0).
            $existing = $s->items()->lockForUpdate()->get();

            foreach ($existing as $item) {
                $item->forceFill(['system_qty' => $balances->get($item->product_id, '0.000')])->save();
            }

            $known = $existing->pluck('product_id')->all();

            // Semua produk bersaldo > 0 (termasuk produk nonaktif yang stoknya masih ada).
            foreach ($balances as $productId => $qty) {
                if (bccomp($qty, '0', 3) > 0 && !in_array($productId, $known)) {
                    $s->items()->make(['product_id' => $productId])->forceFill(['system_qty' => $qty])->save();
                }
            }

            if (!$s->items()->exists()) {
                throw new DomainException('Tidak ada produk untuk dihitung. Tambahkan produk pada draft.');
            }

            $s->forceFill([
                'status' => StocktakeStatus::Counting,
                'started_at' => now(),
                'started_by' => $user->id,
            ])->save();

            return $s;
        });
    }
}
