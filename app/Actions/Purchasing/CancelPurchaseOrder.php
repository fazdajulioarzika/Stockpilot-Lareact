<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\AuditLog;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CancelPurchaseOrder extends PurchaseOrderAction
{
    /** Hanya sebelum ada barang diterima. Setelah itu gunakan Tutup PO. */
    public function execute(PurchaseOrder $order, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $user) {
            $o = $this->lockInStatus(
                $order,
                PurchaseOrderStatus::Draft,
                PurchaseOrderStatus::Submitted,
                PurchaseOrderStatus::Approved,
            );

            $o->forceFill(['status' => PurchaseOrderStatus::Cancelled, 'completed_at' => now()])->save();

            AuditLog::record('purchase_order.cancelled', $o, $user);

            return $o;
        });
    }
}
