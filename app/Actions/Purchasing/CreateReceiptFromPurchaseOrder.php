<?php

namespace App\Actions\Purchasing;

use App\Enums\DocumentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreateReceiptFromPurchaseOrder extends PurchaseOrderAction
{
    /** Membuat draft Barang Masuk berisi sisa pesanan. Stok belum berubah. */
    public function execute(PurchaseOrder $order, User $user): GoodsReceipt
    {
        return DB::transaction(function () use ($order, $user) {
            $o = $this->lockInStatus($order, PurchaseOrderStatus::Approved, PurchaseOrderStatus::PartiallyReceived);

            // PO terkunci, jadi dua permintaan serentak tidak bisa sama-sama lolos.
            $open = GoodsReceipt::query()
                ->where('purchase_order_id', $o->id)
                ->where('status', DocumentStatus::Draft->value)
                ->first(['id', 'number']);

            if ($open) {
                throw new DomainException("Sudah ada draft penerimaan ({$open->number}) untuk PO ini. Selesaikan atau hapus dulu.");
            }

            $lines = $o->items()->with('product:id,sku,is_active')->orderBy('product_id')->get()
                ->filter(fn ($i) => $i->product->is_active && bccomp($i->outstanding(), '0', 3) > 0);

            if ($lines->isEmpty()) {
                throw new DomainException('Tidak ada sisa pesanan yang dapat diterima.');
            }

            $receipt = GoodsReceipt::create([
                'warehouse_id' => $o->warehouse_id,
                'supplier_id' => $o->supplier_id,
                'purchase_order_id' => $o->id,
                'reference' => $o->number,
                'created_by' => $user->id,
            ]);

            foreach ($lines as $line) {
                $receipt->items()->create([
                    'product_id' => $line->product_id,
                    'quantity' => $line->outstanding(),
                    'unit_cost' => $line->unit_cost,
                ]);
            }

            return $receipt;
        });
    }
}
