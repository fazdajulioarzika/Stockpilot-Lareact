<?php

namespace App\Actions\Inventory;

use App\Enums\DocumentStatus;
use App\Enums\MovementType;
use App\Exceptions\DocumentAlreadyPostedException;
use App\Models\GoodsReceipt;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use DomainException;
use Illuminate\Support\Facades\DB;
use App\Models\AuditLog;

class PostGoodsReceipt
{
    public function __construct(private StockService $stock)
    {
    }

    public function execute(GoodsReceipt $receipt, User $user): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $user) {
            // Kunci dokumen. Request kedua menunggu di sini, lalu melihat status sudah posted.
            $receipt = GoodsReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();

            if ($receipt->status !== DocumentStatus::Draft) {
                throw new DocumentAlreadyPostedException();
            }

            $items = $receipt->items()->with('product:id,sku,is_active')->orderBy('product_id')->get();

            if ($items->isEmpty()) {
                throw new DomainException('Dokumen belum memiliki item.');
            }

            if (!Warehouse::whereKey($receipt->warehouse_id)->value('is_active')) {
                throw new DomainException('Gudang tidak aktif.');
            }

            foreach ($items as $item) {
                if (!$item->product->is_active) {
                    throw new DomainException("Produk {$item->product->sku} tidak aktif.");
                }

                if (bccomp((string) $item->quantity, '0', 3) <= 0) {
                    throw new DomainException("Kuantitas produk {$item->product->sku} harus lebih besar dari nol.");
                }

                $this->stock->apply(
                    $receipt->warehouse_id,
                    $item->product_id,
                    (string) $item->quantity,
                    MovementType::Receipt,
                    $receipt,
                    $user->id,
                );
            }

            $receipt->forceFill([
                'status' => DocumentStatus::Posted,
                'posted_at' => now(),
                'posted_by' => $user->id,
            ])->save();
            AuditLog::record('goods_receipt.posted', $receipt, $user, ['warehouse_id' => $receipt->warehouse_id, 'items' => $items->count()]);
            return $receipt;
        });
    }
}
