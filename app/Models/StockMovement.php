<?php

namespace App\Models;

use App\Enums\MovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StockMovement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'product_id', 'warehouse_id', 'movement_type', 'quantity_delta',
        'reference_type', 'reference_id', 'created_by', 'created_at', 'value_delta'
    ];

    protected function casts(): array
    {
        return [
            'movement_type' => MovementType::class,
            'quantity_delta' => 'decimal:3',
            'created_at' => 'datetime',
            'value_delta' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StockMovement $m) {
            $m->created_at ??= now();
        });

        // Ledger bersifat append-only (PRD 6.1). Koreksi dilakukan lewat movement baru.
        static::updating(fn () => throw new LogicException('Ledger tidak boleh diubah.'));
        static::deleting(fn () => throw new LogicException('Ledger tidak boleh dihapus.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
