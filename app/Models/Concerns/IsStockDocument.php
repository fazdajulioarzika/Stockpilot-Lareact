<?php

namespace App\Models\Concerns;

use App\Enums\DocumentStatus;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait IsStockDocument
{
    abstract public static function documentPrefix(): string;

    protected static function bootIsStockDocument(): void
    {
        // Nomor dokumen dibuat dari ID, sehingga unik tanpa race condition.
        static::created(function ($doc) {
            $doc->forceFill([
                'number' => $doc::documentPrefix() . '-' . str_pad((string) $doc->id, 6, '0', STR_PAD_LEFT),
            ])->saveQuietly();
        });

        $guard = function ($doc) {
            if ($doc->getRawOriginal('status') === DocumentStatus::Posted->value) {
                throw new LogicException('Dokumen yang sudah diposting tidak boleh diubah atau dihapus.');
            }
        };

        static::updating($guard);
        static::deleting($guard);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::Draft;
    }
}
