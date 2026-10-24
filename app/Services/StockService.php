<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class StockService
{
    /**
     * Ubah saldo satu produk di satu gudang dan catat ledger-nya.
     *
     * @param string $delta Bertanda, string agar presisi bcmath terjaga ("10.000" atau "-5.000").
     */
    public function apply(
        int $warehouseId,
        int $productId,
        string $delta,
        MovementType $type,
        Model $reference,
        int $userId,
    ): StockMovement {
        // Saldo dan ledger harus berubah dalam transaksi yang sama (PRD 6.1).
        if (DB::transactionLevel() === 0) {
            throw new LogicException('StockService::apply harus dipanggil di dalam DB::transaction.');
        }

        if (bccomp($delta, '0', 3) === 0) {
            throw new InvalidArgumentException('Perubahan stok tidak boleh nol.');
        }

        // Buat baris saldo jika belum ada. Aman terhadap request bersamaan
        // karena ada unique (warehouse_id, product_id).
        DB::table('inventory_stocks')->insertOrIgnore([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Kunci baris. Request lain untuk produk dan gudang yang sama menunggu di sini.
        $stock = InventoryStock::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->firstOrFail();

        $newQty = bcadd((string) $stock->quantity, $delta, 3);

        if (bccomp($newQty, '0', 3) < 0) {
            $label = Product::whereKey($productId)->value('sku') ?? "ID {$productId}";

            throw new InsufficientStockException($label, $warehouseId);
        }

        $stock->quantity = $newQty;
        $stock->save();

        return StockMovement::create([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'movement_type' => $type,
            'quantity_delta' => $delta,
            'reference_type' => $reference->getMorphClass(),
            'reference_id' => $reference->getKey(),
            'created_by' => $userId,
        ]);
    }
}
