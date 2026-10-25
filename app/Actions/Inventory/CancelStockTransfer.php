<?php

namespace App\Actions\Inventory;

use App\Enums\TransferStatus;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Models\AuditLog;

class CancelStockTransfer extends TransferAction
{
    /** Hanya sebelum dikirim. Setelah dikirim, sisa diselesaikan lewat retur. */
    public function execute(StockTransfer $transfer, User $user): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $user) {
            $t = $this->lockInStatus(
                $transfer,
                TransferStatus::Draft,
                TransferStatus::PendingApproval,
                TransferStatus::Approved,
            );

            $t->forceFill(['status' => TransferStatus::Cancelled, 'completed_at' => now()])->save();

            AuditLog::record('transfer.cancelled', $t, $user);

            return $t;
        });
    }
}
