<?php

namespace App\Models;

use App\Enums\TransferStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class StockTransfer extends Model
{
    protected $attributes = ['status' => 'draft'];

    // Status dan kolom jejak hanya diubah oleh action.
    protected $fillable = ['source_warehouse_id', 'destination_warehouse_id', 'reference', 'notes', 'created_by'];

    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (StockTransfer $t) {
            $t->forceFill(['number' => 'TR-' . str_pad((string) $t->id, 6, '0', STR_PAD_LEFT)])->saveQuietly();
        });

        // Gudang tidak boleh diganti setelah keluar dari draft.
        static::updating(function (StockTransfer $t) {
            if (
                $t->getRawOriginal('status') !== TransferStatus::Draft->value
                && $t->isDirty(['source_warehouse_id', 'destination_warehouse_id'])
            ) {
                throw new LogicException('Gudang transfer tidak boleh diubah setelah draft.');
            }
        });

        static::deleting(function (StockTransfer $t) {
            if ($t->getRawOriginal('status') !== TransferStatus::Draft->value) {
                throw new LogicException('Hanya draft yang boleh dihapus.');
            }
        });
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(StockTransferEvent::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }
}
