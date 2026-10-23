<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Warehouse extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'address', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }


    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_warehouses')->withTimestamps();
    }

    /** Batasi query ke gudang yang boleh diakses user (dipakai di semua daftar bersekop gudang). */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        $ids = $user->accessibleWarehouseIds();

        return $ids === null ? $query : $query->whereIn('warehouses.id', $ids);
    }
}
