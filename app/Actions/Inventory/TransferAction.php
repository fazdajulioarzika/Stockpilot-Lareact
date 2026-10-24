<?php

namespace App\Actions\Inventory;

use App\Enums\TransferStatus;
use App\Exceptions\InvalidTransferStateException;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Support\Collection;

abstract class TransferAction
{
    /** Kunci baris transfer. Request kedua menunggu, lalu melihat status yang sudah berubah. */
    protected function lockInStatus(StockTransfer $transfer, TransferStatus ...$allowed): StockTransfer
    {
        $locked = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

        if (!in_array($locked->status, $allowed, true)) {
            throw new InvalidTransferStateException($locked->status->value);
        }

        return $locked;
    }

    /** Dipakai saat submit dan dispatch (transaksi baru memakai master aktif). */
    protected function assertUsable(StockTransfer $transfer, Collection $items): void
    {
        if ((int) $transfer->source_warehouse_id === (int) $transfer->destination_warehouse_id) {
            throw new DomainException('Gudang sumber dan tujuan harus berbeda.');
        }

        $inactive = Warehouse::whereIn('id', [$transfer->source_warehouse_id, $transfer->destination_warehouse_id])
            ->where('is_active', false)
            ->exists();

        if ($inactive) {
            throw new DomainException('Gudang sumber atau tujuan tidak aktif.');
        }

        if ($items->isEmpty()) {
            throw new DomainException('Transfer belum memiliki item.');
        }

        foreach ($items as $item) {
            if (!$item->product->is_active) {
                throw new DomainException("Produk {$item->product->sku} tidak aktif.");
            }

            if (bccomp((string) $item->quantity, '0', 3) <= 0) {
                throw new DomainException("Kuantitas produk {$item->product->sku} harus lebih besar dari nol.");
            }
        }
    }
}
