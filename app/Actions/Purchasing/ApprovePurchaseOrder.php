<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\AuditLog;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ApprovePurchaseOrder extends PurchaseOrderAction
{
    /** Pemisahan tugas diperiksa policy, bukan di sini. */
    public function execute(PurchaseOrder $order, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $user) {
            $o = $this->lockInStatus($order, PurchaseOrderStatus::Submitted);

            $o->forceFill([
                'status' => PurchaseOrderStatus::Approved,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ])->save();

            AuditLog::record('purchase_order.approved', $o, $user);

            return $o;
        });
    }
}
