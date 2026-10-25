<?php

namespace App\Http\Requests;

use App\Models\Stocktake;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StocktakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (!$user) {
            return false;
        }

        // Ada opname di route = ubah draft, tidak ada = buat baru.
        $stocktake = $this->route('stocktake');

        $allowed = $stocktake
            ? $user->can('update', $stocktake)
            : $user->can('create', Stocktake::class);

        if (!$allowed) {
            return false;
        }

        $warehouseId = (int) $this->input('warehouse_id');

        return $warehouseId === 0 || $user->canAccessWarehouse($warehouseId);
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Produk tambahan (stok sistem 0 tapi mungkin ada fisiknya). Produk bersaldo masuk otomatis.
            'product_ids' => ['nullable', 'array', 'max:200'],
            'product_ids.*' => [
                'integer', 'distinct',
                Rule::exists('products', 'id')->where('is_active', true),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'warehouse_id' => 'gudang',
            'notes' => 'catatan',
            'product_ids' => 'produk tambahan',
            'product_ids.*' => 'produk',
        ];
    }
}
