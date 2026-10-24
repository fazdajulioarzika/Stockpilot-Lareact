<?php

namespace App\Actions\Inventory;

use App\Enums\TransferStatus;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SubmitStockTransfer extends TransferAction
{
    public function execute(StockTransfer $transfer, User $user): StockTransfer
    {
        return DB::transaction(function () use ($transfer) {
            $t = $this->lockInStatus($transfer, TransferStatus::Draft);

            $items = $t->items()->with('product:id,sku,is_active')->get();
            $this->assertUsable($t, $items);

            $needsApproval = (bool) config('stockpilot.transfer_requires_approval');

            $t->forceFill([
                'status' => $needsApproval ? TransferStatus::PendingApproval : TransferStatus::Approved,
                'submitted_at' => now(),
                // Tanpa approval, persetujuan otomatis (approved_by tetap kosong).
                'approved_at' => $needsApproval ? null : now(),
            ])->save();

            return $t;
        });
    }
}
