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

class ReceiveStockTransfer extends TransferAction
{
    public function __construct(private StockService $stock)
    {
    }

    /**
     * @param array<int, array{item_id: int, quantity: string}> $lines
     */
    public function execute(StockTransfer $transfer, User $user, array $lines, string $idempotencyKey): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $user, $lines, $idempotencyKey) {
            $t = $this->lockInStatus($transfer, TransferStatus::InTransit, TransferStatus::PartiallyReceived);

            // Transfer sudah terkunci, jadi pengecekan ini aman dari request bersamaan.
            if ($t->events()->where('idempotency_key', $idempotencyKey)->exists()) {
                throw new DomainException('Penerimaan ini sudah diproses.');
            }

            if ($lines === []) {
                throw new DomainException('Tidak ada item yang diterima.');
            }

            $itemIds = array_map(fn ($l) => (int) $l['item_id'], $lines);

            if (count($itemIds) !== count(array_unique($itemIds))) {
                throw new DomainException('Item yang sama tidak boleh muncul dua kali.');
            }

            $items = $t->items()->orderBy('product_id')->lockForUpdate()->get()->keyBy('id');
            $byItem = collect($lines)->keyBy(fn ($l) => (int) $l['item_id']);

            // Urut product_id (bukan urutan input) agar urutan lock konsisten.
            foreach ($items as $item) {
                $line = $byItem->get($item->id);

                if (!$line) {
                    continue;
                }

                $qty = (string) $line['quantity'];

                if (bccomp($qty, '0', 3) <= 0) {
                    throw new DomainException('Kuantitas diterima harus lebih besar dari nol.');
                }

                if (bccomp($qty, $item->remaining(), 3) > 0) {
                    throw new DomainException('Kuantitas diterima melebihi jumlah yang masih dalam perjalanan.');
                }

                $this->stock->apply(
                    $t->destination_warehouse_id,
                    $item->product_id,
                    $qty,
                    MovementType::TransferIn,
                    $t,
                    $user->id,
                );

                $item->forceFill(['qty_received' => bcadd((string) $item->qty_received, $qty, 3)])->save();
            }

            if ($byItem->keys()->diff($items->keys())->isNotEmpty()) {
                throw new DomainException('Ada item yang bukan bagian dari transfer ini.');
            }

            $done = $items->every(fn ($i) => bccomp($i->remaining(), '0', 3) === 0);

            $t->forceFill([
                'status' => $done ? TransferStatus::Received : TransferStatus::PartiallyReceived,
                'completed_at' => $done ? now() : null,
            ])->save();

            StockTransferEvent::create([
                'stock_transfer_id' => $t->id,
                'type' => 'receive',
                'idempotency_key' => $idempotencyKey,
                'created_by' => $user->id,
            ]);

            return $t;
        });
    }
}
