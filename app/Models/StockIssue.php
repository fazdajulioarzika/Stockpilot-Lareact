<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Models\Concerns\IsStockDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockIssue extends Model
{
    use IsStockDocument;

    protected $attributes = ['status' => 'draft'];

    protected $fillable = ['warehouse_id', 'reason', 'reference', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['status' => DocumentStatus::class, 'posted_at' => 'datetime'];
    }

    public static function documentPrefix(): string
    {
        return 'SI';
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockIssueItem::class);
    }
}
