<?php

namespace App\Actions\Inventory;

use App\Enums\DocumentStatus;
use App\Enums\MovementType;
use App\Exceptions\DocumentAlreadyPostedException;
use App\Models\StockIssue;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use DomainException;
use Illuminate\Support\Facades\DB;

class PostStockIssue
{
    public function __construct(private StockService $stock)
    {
    }

    public function execute(StockIssue $issue, User $user): StockIssue
    {
        return DB::transaction(function () use ($issue, $user) {
            $issue = StockIssue::query()->whereKey($issue->id)->lockForUpdate()->firstOrFail();

            if ($issue->status !== DocumentStatus::Draft) {
                throw new DocumentAlreadyPostedException();
            }

            // Urut berdasarkan product_id agar urutan lock konsisten antar request (mencegah deadlock).
            $items = $issue->items()->with('product:id,sku,is_active')->orderBy('product_id')->get();

            if ($items->isEmpty()) {
                throw new DomainException('Dokumen belum memiliki item.');
            }

            if (!Warehouse::whereKey($issue->warehouse_id)->value('is_active')) {
                throw new DomainException('Gudang tidak aktif.');
            }

            foreach ($items as $item) {
                if (!$item->product->is_active) {
                    throw new DomainException("Produk {$item->product->sku} tidak aktif.");
                }

                if (bccomp((string) $item->quantity, '0', 3) <= 0) {
                    throw new DomainException("Kuantitas produk {$item->product->sku} harus lebih besar dari nol.");
                }

                // Jika stok kurang, StockService melempar exception dan seluruh transaksi di-rollback.
                $this->stock->apply(
                    $issue->warehouse_id,
                    $item->product_id,
                    bcmul((string) $item->quantity, '-1', 3),
                    MovementType::Issue,
                    $issue,
                    $user->id,
                );
            }

            $issue->forceFill([
                'status' => DocumentStatus::Posted,
                'posted_at' => now(),
                'posted_by' => $user->id,
            ])->save();

            return $issue;
        });
    }
}
