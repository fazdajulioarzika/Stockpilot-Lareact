<?php

namespace App\Models;

use App\Enums\StocktakeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Stocktake extends Model
{
    protected $attributes = ['status' => 'draft'];

    // Status dan kolom jejak hanya diubah oleh action.
    protected $fillable = ['warehouse_id', 'notes', 'created_by'];

    protected function casts(): array
    {
        return [
            'status' => StocktakeStatus::class,
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'posted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Stocktake $s) {
            $s->forceFill(['number' => 'SO-' . str_pad((string) $s->id, 6, '0', STR_PAD_LEFT)])->saveQuietly();
        });

        static::updating(function (Stocktake $s) {
            $original = $s->getRawOriginal('status');

            if (in_array($original, [StocktakeStatus::Posted->value, StocktakeStatus::Cancelled->value], true)) {
                throw new LogicException('Opname yang sudah selesai tidak boleh diubah.');
            }

            if ($original !== StocktakeStatus::Draft->value && $s->isDirty('warehouse_id')) {
                throw new LogicException('Gudang opname tidak boleh diubah setelah draft.');
            }
        });

        static::deleting(function (Stocktake $s) {
            if ($s->getRawOriginal('status') !== StocktakeStatus::Draft->value) {
                throw new LogicException('Hanya draft yang boleh dihapus.');
            }
        });
    }

    /**
     * Opname aktif yang mengunci gudang, atau null.
     *
     * Memakai locking read (bukan baca biasa) agar selalu melihat data terbaru yang sudah
     * di-commit, walaupun transaksi pemanggil sudah punya snapshot lama.
     * Harus dipanggil di dalam transaksi.
     */
    public static function activeLockFor(int $warehouseId): ?self
    {
        return static::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('status', array_map(fn (StocktakeStatus $s) => $s->value, StocktakeStatus::locking()))
            ->sharedLock()
            ->first(['id', 'number']);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StocktakeItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
