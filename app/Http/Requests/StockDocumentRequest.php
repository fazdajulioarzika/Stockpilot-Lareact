<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class StockDocumentRequest extends FormRequest
{
    /** @return class-string */
    abstract protected function documentClass(): string;

    /** Nama parameter route, contoh: goods_receipt */
    abstract protected function routeParameter(): string;

    abstract protected function extraRules(): array;

    protected function extraAttributes(): array
    {
        return [];
    }

    public function authorize(): bool
    {
        $user = $this->user();

        if (!$user) {
            return false;
        }

        // Ada dokumen di route = ubah draft, tidak ada = buat baru.
        $doc = $this->route($this->routeParameter());

        $allowed = $doc
            ? $user->can('update', $doc)
            : $user->can('create', $this->documentClass());

        if (!$allowed) {
            return false;
        }

        // Gudang tujuan juga harus boleh diakses (mencegah manipulasi request).
        $warehouseId = (int) $this->input('warehouse_id');

        return $warehouseId === 0 || $user->canAccessWarehouse($warehouseId);
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('products', 'id')->where('is_active', true),
            ],
            // Lebih dari nol, maksimal 3 desimal (sesuai kolom decimal(15,3)).
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:999999999', 'decimal:0,3'],
            ...$this->extraRules(),
        ];
    }

    public function attributes(): array
    {
        return [
            'warehouse_id' => 'gudang',
            'reference' => 'referensi',
            'notes' => 'catatan',
            'items' => 'item',
            'items.*.product_id' => 'produk',
            'items.*.quantity' => 'kuantitas',
            ...$this->extraAttributes(),
        ];
    }
}
