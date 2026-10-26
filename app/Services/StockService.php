<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\WarehouseLockedException;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\Warehouse;
use App\Support\Money;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class StockService
{
    /**
     * Ubah saldo satu produk di satu gudang dan catat ledger-nya (kuantitas dan nilai).
     *
     * @param string      $delta        Bertanda, string agar presisi bcmath terjaga ("10.000" atau "-5.000").
     * @param string|null $inflowValue  Nilai total yang masuk (hanya untuk delta positif). Jika kosong,
     *                                  dinilai pada rata-rata saat ini. Delta negatif selalu keluar pada rata-rata.
     */
    public function apply(
        int $warehouseId,
        int $productId,
        string $delta,
        MovementType $type,
        Model $reference,
        int $userId,
        ?string $inflowValue = null,
    ): StockMovement {
        // Saldo dan ledger harus berubah dalam transaksi yang sama (PRD 6.1).
        if (DB::transactionLevel() === 0) {
            throw new LogicException('StockService::apply harus dipanggil di dalam DB::transaction.');
        }

        if (bccomp($delta, '0', 3) === 0) {
            throw new InvalidArgumentException('Perubahan stok tidak boleh nol.');
        }

        if ($type !== MovementType::Adjustment) {
            $this->assertWarehouseOpen($warehouseId);
        }

        // Upsert (bukan insert ignore) agar kunci tulis langsung diambil dan tidak terjadi deadlock.
        DB::table('inventory_stocks')->upsert(
            [[
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['warehouse_id', 'product_id'],
            ['updated_at'],
        );

        // Kunci baris. Request lain untuk produk dan gudang yang sama menunggu di sini.
        $stock = InventoryStock::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->firstOrFail();

        $oldQty = (string) $stock->quantity;
        $oldValue = (string) $stock->total_value;
        $newQty = bcadd($oldQty, $delta, 3);

        if (bccomp($newQty, '0', 3) < 0) {
            $label = Product::whereKey($productId)->value('sku') ?? "ID {$productId}";

            throw new InsufficientStockException($label, $warehouseId);
        }

        $valueDelta = bccomp($delta, '0', 3) > 0
            ? $this->inflowValue($productId, $delta, $oldQty, $oldValue, $inflowValue)
            : $this->outflowValue($oldQty, $oldValue, $delta, $newQty);

        $stock->quantity = $newQty;
        $stock->total_value = Money::normalize(bcadd($oldValue, $valueDelta, 4));
        $stock->save();

        return StockMovement::create([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'movement_type' => $type,
            'quantity_delta' => $delta,
            'value_delta' => $valueDelta,
            'reference_type' => $reference->getMorphClass(),
            'reference_id' => $reference->getKey(),
            'created_by' => $userId,
        ]);
    }

    /**
     * Nilai ulang saldo yang kuantitasnya tidak berubah (mengisi biaya awal stok lama).
     * Mengembalikan null jika nilainya sudah sama.
     */
    public function revalue(
        int $warehouseId,
        int $productId,
        string $newTotalValue,
        Model $reference,
        int $userId,
    ): ?StockMovement {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('StockService::revalue harus dipanggil di dalam DB::transaction.');
        }

        $stock = InventoryStock::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        if (!$stock || bccomp((string) $stock->quantity, '0', 3) <= 0) {
            throw new DomainException('Tidak ada saldo yang dapat dinilai ulang.');
        }

        $new = Money::normalize($newTotalValue);

        if (bccomp($new, '0', 4) < 0) {
            throw new InvalidArgumentException('Nilai persediaan tidak boleh negatif.');
        }

        $valueDelta = Money::normalize(bcsub($new, (string) $stock->total_value, 4));

        if (bccomp($valueDelta, '0', 4) === 0) {
            return null;
        }

        $stock->total_value = $new;
        $stock->save();

        return StockMovement::create([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'movement_type' => MovementType::Revaluation,
            'quantity_delta' => '0.000',
            'value_delta' => $valueDelta,
            'reference_type' => $reference->getMorphClass(),
            'reference_id' => $reference->getKey(),
            'created_by' => $userId,
        ]);
    }

    private function inflowValue(int $productId, string $delta, string $oldQty, string $oldValue, ?string $provided): string
    {
        if ($provided !== null) {
            $value = Money::normalize($provided);

            if (bccomp($value, '0', 4) < 0) {
                throw new InvalidArgumentException('Nilai persediaan tidak boleh negatif.');
            }

            return $value;
        }

        // Tanpa nilai eksplisit: pada rata-rata saat ini.
        if (bccomp($oldQty, '0', 3) > 0 && bccomp($oldValue, '0', 4) > 0) {
            return Money::round4(bcdiv(bcmul($oldValue, $delta, 7), $oldQty, 8));
        }

        // Gudang kosong: biaya penerimaan terakhir produk ini. Jika belum ada, nol.
        $last = StockMovement::query()
            ->where('product_id', $productId)
            ->where('movement_type', MovementType::Receipt->value)
            ->where('value_delta', '>', 0)
            ->orderByDesc('id')
            ->first(['value_delta', 'quantity_delta']);

        if ($last) {
            return Money::round4(bcdiv(bcmul((string) $last->value_delta, $delta, 7), (string) $last->quantity_delta, 8));
        }

        return '0.0000';
    }

    private function outflowValue(string $oldQty, string $oldValue, string $delta, string $newQty): string
    {
        if (bccomp($oldValue, '0', 4) === 0) {
            return '0.0000';
        }

        // Saldo habis: seluruh nilai ikut keluar, tidak ada sisa pembulatan.
        if (bccomp($newQty, '0', 3) === 0) {
            return Money::normalize(bcmul($oldValue, '-1', 4));
        }

        $out = bcmul($delta, '-1', 3);
        $removed = Money::round4(bcdiv(bcmul($oldValue, $out, 7), $oldQty, 8));

        if (bccomp($removed, $oldValue, 4) > 0) {
            $removed = $oldValue;
        }

        return Money::normalize(bcmul($removed, '-1', 4));
    }

    /**
     * Tolak jika gudang sedang opname.
     *
     * Kunci baca pada baris gudang membuat "mulai opname" (kunci eksklusif) menunggu semua
     * posting yang sedang berjalan selesai. Posting lain tetap bisa berjalan bersamaan.
     */
    private function assertWarehouseOpen(int $warehouseId): void
    {
        $code = Warehouse::query()->whereKey($warehouseId)->sharedLock()->value('code');

        if (Stocktake::activeLockFor($warehouseId) !== null) {
            throw new WarehouseLockedException($code ?? "ID {$warehouseId}");
        }
    }
}
