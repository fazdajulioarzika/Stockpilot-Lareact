<?php

namespace App\Http\Requests;

use App\Models\StockTransfer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (!$user) {
            return false;
        }

        // Ada transfer di route = ubah draft, tidak ada = buat baru.
        $transfer = $this->route('stock_transfer');

        $allowed = $transfer
            ? $user->can('update', $transfer)
            : $user->can('create', StockTransfer::class);

        if (!$allowed) {
            return false;
        }

        // Gudang sumber harus gudang yang boleh diakses (mencegah manipulasi request).
        $source = (int) $this->input('source_warehouse_id');

        return $source === 0 || $user->canAccessWarehouse($source);
    }

    public function rules(): array
    {
        $activeWarehouse = Rule::exists('warehouses', 'id')->where('is_active', true);

        return [
            'source_warehouse_id' => ['required', 'integer', $activeWarehouse],
            'destination_warehouse_id' => ['required', 'integer', 'different:source_warehouse_id', $activeWarehouse],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('products', 'id')->where('is_active', true),
            ],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:999999999', 'decimal:0,3'],
        ];
    }

    public function attributes(): array
    {
        return [
            'source_warehouse_id' => 'gudang sumber',
            'destination_warehouse_id' => 'gudang tujuan',
            'reference' => 'referensi',
            'notes' => 'catatan',
            'items' => 'item',
            'items.*.product_id' => 'produk',
            'items.*.quantity' => 'kuantitas',
        ];
    }

    public function messages(): array
    {
        return [
            'destination_warehouse_id.different' => 'Gudang tujuan harus berbeda dari gudang sumber.',
        ];
    }
}
