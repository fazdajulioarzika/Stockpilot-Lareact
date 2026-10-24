<?php

namespace App\Models;

use App\Enums\TransferStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StockTransferItem extends Model
{
    // qty_sent/qty_received/qty_returned sengaja tidak fillable: hanya diubah action.
    protected $fillable = ['stock_transfer_id', 'product_id', 'quantity'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'qty_sent' => 'decimal:3',
            'qty_received' => 'decimal:3',
            'qty_returned' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        // Produk dan jumlah rencana hanya boleh berubah selama transfer masih draft.
        static::saving(function (StockTransferItem $item) {
            $transfer = $item->transfer()->first();

            if (
                $transfer && $transfer->status !== TransferStatus::Draft
                && $item->isDirty(['stock_transfer_id', 'product_id', 'quantity'])
            ) {
                throw new LogicException('Item transfer tidak boleh diubah setelah draft.');
            }
        });

        static::deleting(function (StockTransferItem $item) {
            if ($item->transfer()->first()?->status !== TransferStatus::Draft) {
                throw new LogicException('Item transfer tidak boleh dihapus setelah draft.');
            }
        });
    }

    /** Jumlah yang masih dalam perjalanan. */
    public function remaining(): string
    {
        return bcsub(
            (string) $this->qty_sent,
            bcadd((string) $this->qty_received, (string) $this->qty_returned, 3),
            3,
        );
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
