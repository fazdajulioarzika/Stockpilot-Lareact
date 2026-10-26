<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Models\Concerns\IsStockDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceipt extends Model
{
    use IsStockDocument;

    protected $attributes = ['status' => 'draft'];

    // status, posted_at, posted_by sengaja tidak fillable: hanya diubah oleh action posting.
    protected $fillable = ['warehouse_id', 'supplier_id', 'reference', 'notes', 'created_by', 'purchase_order_id'];

    protected function casts(): array
    {
        return ['status' => DocumentStatus::class, 'posted_at' => 'datetime'];
    }

    public static function documentPrefix(): string
    {
        return 'GR';
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
