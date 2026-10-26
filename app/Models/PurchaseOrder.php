<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class PurchaseOrder extends Model
{
    protected $attributes = ['status' => 'draft'];

    // Status dan kolom jejak hanya diubah oleh action.
    protected $fillable = ['supplier_id', 'warehouse_id', 'reference', 'notes', 'expected_date', 'created_by'];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'expected_date' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'closed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (PurchaseOrder $o) {
            $o->forceFill(['number' => 'PO-' . str_pad((string) $o->id, 6, '0', STR_PAD_LEFT)])->saveQuietly();
        });

        // Pihak yang terlibat tidak boleh diganti setelah keluar dari draft.
        static::updating(function (PurchaseOrder $o) {
            if (
                $o->getRawOriginal('status') !== PurchaseOrderStatus::Draft->value
                && $o->isDirty(['supplier_id', 'warehouse_id'])
            ) {
                throw new LogicException('Supplier dan gudang PO tidak boleh diubah setelah draft.');
            }
        });

        static::deleting(function (PurchaseOrder $o) {
            if ($o->getRawOriginal('status') !== PurchaseOrderStatus::Draft->value) {
                throw new LogicException('Hanya draft yang boleh dihapus.');
            }
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
