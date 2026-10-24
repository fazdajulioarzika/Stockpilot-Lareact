<?php

namespace App\Models;

use App\Enums\StocktakeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StocktakeItem extends Model
{
    // system_qty, counted_qty, difference, reason sengaja tidak fillable: hanya diubah action.
    protected $fillable = ['stocktake_id', 'product_id'];

    protected function casts(): array
    {
        return [
            'system_qty' => 'decimal:3',
            'counted_qty' => 'decimal:3',
            'difference' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (StocktakeItem $item) {
            $status = $item->stocktake()->first()?->status;

            if (in_array($status, [StocktakeStatus::Posted, StocktakeStatus::Cancelled], true)) {
                throw new LogicException('Item opname yang sudah selesai tidak boleh diubah.');
            }
        });

        static::deleting(function (StocktakeItem $item) {
            if ($item->stocktake()->first()?->status !== StocktakeStatus::Draft) {
                throw new LogicException('Item opname hanya boleh dihapus saat draft.');
            }
        });
    }

    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
