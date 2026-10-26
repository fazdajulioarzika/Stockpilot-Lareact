<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class DocumentReversal extends Model
{
    public $timestamps = false;

    protected $fillable = ['document_type', 'document_id', 'reason', 'reversed_by', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (DocumentReversal $r) => $r->created_at ??= now());

        // Append-only, sama seperti ledger dan audit log.
        static::updating(fn () => throw new LogicException('Catatan pembalikan tidak boleh diubah.'));
        static::deleting(fn () => throw new LogicException('Catatan pembalikan tidak boleh dihapus.'));
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
