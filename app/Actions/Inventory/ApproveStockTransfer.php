<?php

namespace App\Actions\Inventory;

use App\Enums\TransferStatus;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ApproveStockTransfer extends TransferAction
{
    public function execute(StockTransfer $transfer, User $user): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $user) {
            $t = $this->lockInStatus($transfer, TransferStatus::PendingApproval);

            $t->forceFill([
                'status' => TransferStatus::Approved,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ])->save();

            return $t;
        });
    }
}
