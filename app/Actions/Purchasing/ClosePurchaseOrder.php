<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\AuditLog;
use App\Models\PurchaseOrder;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class ClosePurchaseOrder extends PurchaseOrderAction
{
    /** Menutup PO yang sebagian diterima: sisa pesanan tidak akan dikirim supplier. */
    public function execute(PurchaseOrder $order, User $user, string $reason): PurchaseOrder
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('Alasan penutupan wajib diisi.');
        }

        if (mb_strlen($reason) > 255) {
            throw new DomainException('Alasan penutupan maksimal 255 karakter.');
        }

        return DB::transaction(function () use ($order, $user, $reason) {
            $o = $this->lockInStatus($order, PurchaseOrderStatus::PartiallyReceived);

            $outstanding = $o->items()->lockForUpdate()->get()
                ->filter(fn ($i) => bccomp($i->outstanding(), '0', 3) > 0)
                ->count();

            $o->forceFill([
                'status' => PurchaseOrderStatus::Received,
                'closed_at' => now(),
                'closed_by' => $user->id,
                'close_reason' => $reason,
                'completed_at' => now(),
            ])->save();

            AuditLog::record('purchase_order.closed', $o, $user, [
                'reason' => $reason,
                'lines_not_fulfilled' => $outstanding,
            ]);

            return $o;
        });
    }
}
