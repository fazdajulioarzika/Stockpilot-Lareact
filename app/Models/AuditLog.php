<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Arr;
use LogicException;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'action', 'entity_type', 'entity_id', 'metadata', 'created_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (AuditLog $l) => $l->created_at ??= now());

        // Append-only, sama seperti ledger stok.
        static::updating(fn () => throw new LogicException('Audit log tidak boleh diubah.'));
        static::deleting(fn () => throw new LogicException('Audit log tidak boleh dihapus.'));
    }

    /**
     * Catat satu aktivitas. Panggil di dalam transaksi action agar ikut rollback jika action gagal.
     * Kunci yang berbau rahasia dibuang sebagai pengaman terakhir (PRD 7.1).
     */
    public static function record(string $action, Model $entity, ?User $user, array $metadata = []): self
    {
        $metadata = Arr::except($metadata, ['password', 'password_confirmation', 'token', 'secret']);

        return static::create([
            'user_id' => $user?->id,
            'action' => $action,
            'entity_type' => $entity->getMorphClass(),
            'entity_id' => $entity->getKey(),
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entity(): MorphTo
    {
        return $this->morphTo('entity', 'entity_type', 'entity_id');
    }
}
