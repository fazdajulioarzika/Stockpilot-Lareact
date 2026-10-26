<?php

namespace App\Actions\Inventory;

use App\Enums\DocumentStatus;
use App\Enums\MovementType;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\DocumentAlreadyPostedException;
use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

            // Urutan kunci: dokumen, PO dan itemnya, baru saldo stok (sama dengan pembalikan).
            $order = $receipt->purchase_order_id ? $this->receiveAgainstOrder($receipt, $items) : null;

            foreach ($items as $item) {
                if (!$item->product->is_active) {
                    throw new DomainException("Produk {$item->product->sku} tidak aktif.");
                }

                if (bccomp((string) $item->quantity, '0', 3) <= 0) {
                    throw new DomainException("Kuantitas produk {$item->product->sku} harus lebih besar dari nol.");
                }

                $value = Money::round4(bcmul((string) $item->quantity, (string) $item->unit_cost, 7));

                $this->stock->apply(
                    $receipt->warehouse_id,
                    $item->product_id,
                    (string) $item->quantity,
                    MovementType::Receipt,
                    $receipt,
                    $user->id,
                    $value,
                );
            }

            $receipt->forceFill([
                'status' => DocumentStatus::Posted,
                'posted_at' => now(),
                'posted_by' => $user->id,
            ])->save();

            AuditLog::record('goods_receipt.posted', $receipt, $user, array_filter([
                'warehouse_id' => $receipt->warehouse_id,
                'items' => $items->count(),
                'purchase_order' => $order?->number,
                'purchase_order_status' => $order?->status->value,
            ], fn ($v) => $v !== null));

            return $receipt;
        });
    }

    /** Catat penerimaan pada PO dan ubah statusnya. Semua pelanggaran membatalkan seluruh posting. */
    private function receiveAgainstOrder(GoodsReceipt $receipt, Collection $items): PurchaseOrder
    {
        $order = PurchaseOrder::query()->whereKey($receipt->purchase_order_id)->lockForUpdate()->firstOrFail();

        if (!$order->status->canReceive()) {
            throw new DomainException("PO {$order->number} berstatus {$order->status->label()} dan tidak dapat menerima barang.");
        }

        if ((int) $order->warehouse_id !== (int) $receipt->warehouse_id) {
            throw new DomainException("Gudang penerimaan berbeda dari gudang PO {$order->number}.");
        }

        // Urut product_id agar urutan kunci konsisten antar request (mencegah deadlock).
        $orderItems = $order->items()->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');

        foreach ($items as $item) {
            $line = $orderItems->get($item->product_id)
                ?? throw new DomainException("Produk {$item->product->sku} tidak ada di PO {$order->number}.");

            if (bccomp((string) $item->quantity, $line->outstanding(), 3) > 0) {
                throw new DomainException(
                    "Jumlah {$item->product->sku} melebihi sisa pesanan di PO {$order->number} (sisa {$line->outstanding()})."
                );
            }

            if (bccomp((string) $item->unit_cost, (string) $line->unit_cost, 4) !== 0) {
                throw new DomainException("Harga {$item->product->sku} berbeda dari Purchase Order {$order->number}.");
            }

            $line->forceFill(['qty_received' => bcadd((string) $line->qty_received, (string) $item->quantity, 3)])->save();
        }

        $complete = $orderItems->every(fn ($i) => bccomp($i->outstanding(), '0', 3) === 0);

        $order->forceFill([
            'status' => $complete ? PurchaseOrderStatus::Received : PurchaseOrderStatus::PartiallyReceived,
            'completed_at' => $complete ? now() : null,
        ])->save();

        return $order;
    }
}
