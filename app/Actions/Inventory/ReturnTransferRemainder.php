<?php

namespace App\Actions\Inventory;

use App\Enums\MovementType;
use App\Enums\TransferStatus;
use App\Models\StockTransfer;
use App\Models\StockTransferEvent;
use App\Models\User;
use App\Services\StockService;
use DomainException;
use Illuminate\Support\Facades\DB;
use App\Models\AuditLog;

class ReturnTransferRemainder extends TransferAction
{
    public function __construct(private StockService $stock)
    {
    }

    /** Kembalikan sisa yang masih dalam perjalanan ke gudang sumber dan tutup transfer. */
    public function execute(StockTransfer $transfer, User $user): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $user) {
            $t = $this->lockInStatus($transfer, TransferStatus::InTransit, TransferStatus::PartiallyReceived);

            $items = $t->items()->orderBy('product_id')->lockForUpdate()->get();
            $returned = false;

            foreach ($items as $item) {
                $remaining = $item->remaining();

                if (bccomp($remaining, '0', 3) <= 0) {
                    continue;
                }

                $this->stock->apply(
                    $t->source_warehouse_id,
                    $item->product_id,
                    $remaining,
                    MovementType::TransferReturn,
                    $t,
                    $user->id,
                );

                $item->forceFill(['qty_returned' => bcadd((string) $item->qty_returned, $remaining, 3)])->save();
                $returned = true;
            }

            if (!$returned) {
                throw new DomainException('Tidak ada sisa barang dalam perjalanan.');
            }

            // Tidak ada yang sempat diterima = transfer batal setelah dikirim.
            $anyReceived = $items->contains(fn ($i) => bccomp((string) $i->qty_received, '0', 3) > 0);

            $t->forceFill([
                'status' => $anyReceived ? TransferStatus::Received : TransferStatus::Cancelled,
                'completed_at' => now(),
            ])->save();

            StockTransferEvent::create([
                'stock_transfer_id' => $t->id,
                'type' => 'return',
                'created_by' => $user->id,
            ]);

            AuditLog::record('transfer.remainder_returned', $t, $user);

            return $t;
        });
    }
}
