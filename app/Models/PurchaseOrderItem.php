<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PurchaseOrderItem extends Model
{
    // qty_received sengaja tidak fillable: hanya diubah oleh posting penerimaan dan pembalikannya.
    protected $fillable = ['purchase_order_id', 'product_id', 'quantity', 'unit_cost'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'qty_received' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        // Produk, jumlah, dan harga hanya boleh berubah selama PO masih draft.
        static::saving(function (PurchaseOrderItem $item) {
            $order = $item->purchaseOrder()->first();

            if (
                $order && $order->status !== PurchaseOrderStatus::Draft
                && $item->isDirty(['purchase_order_id', 'product_id', 'quantity', 'unit_cost'])
            ) {
                throw new LogicException('Item PO tidak boleh diubah setelah draft.');
            }
        });

        static::deleting(function (PurchaseOrderItem $item) {
            if ($item->purchaseOrder()->first()?->status !== PurchaseOrderStatus::Draft) {
                throw new LogicException('Item PO tidak boleh dihapus setelah draft.');
            }
        });
    }

    /** Jumlah yang masih menunggu diterima. */
    public function outstanding(): string
    {
        return bcsub((string) $this->quantity, (string) $this->qty_received, 3);
    }

    public function lineTotal(): string
    {
        return Money::round4(bcmul((string) $this->quantity, (string) $this->unit_cost, 7));
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
