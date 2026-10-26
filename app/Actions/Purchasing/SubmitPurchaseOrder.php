<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\AuditLog;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Support\Facades\DB;

class SubmitPurchaseOrder extends PurchaseOrderAction
{
    public function execute(PurchaseOrder $order, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $user) {
            $o = $this->lockInStatus($order, PurchaseOrderStatus::Draft);

            $items = $o->items()->with('product:id,sku,is_active')->get();

            if ($items->isEmpty()) {
                throw new DomainException('Purchase Order belum memiliki item.');
            }

            if (!Supplier::whereKey($o->supplier_id)->value('is_active')) {
                throw new DomainException('Supplier tidak aktif.');
            }

            if (!Warehouse::whereKey($o->warehouse_id)->value('is_active')) {
                throw new DomainException('Gudang tidak aktif.');
            }

            foreach ($items as $item) {
                if (!$item->product->is_active) {
                    throw new DomainException("Produk {$item->product->sku} tidak aktif.");
                }

                if (bccomp((string) $item->quantity, '0', 3) <= 0) {
                    throw new DomainException("Kuantitas produk {$item->product->sku} harus lebih besar dari nol.");
                }
            }

            $needsApproval = (bool) config('stockpilot.po_requires_approval');

            $o->forceFill([
                'status' => $needsApproval ? PurchaseOrderStatus::Submitted : PurchaseOrderStatus::Approved,
                'submitted_at' => now(),
                'submitted_by' => $user->id,
                // Tanpa approval, persetujuan otomatis (approved_by tetap kosong).
                'approved_at' => $needsApproval ? null : now(),
            ])->save();

            AuditLog::record('purchase_order.submitted', $o, $user, [
                'status' => $o->status->value,
                'items' => $items->count(),
                'total' => $items->reduce(fn ($carry, $i) => bcadd($carry, $i->lineTotal(), 4), '0'),
            ]);

            return $o;
        });
    }
}
