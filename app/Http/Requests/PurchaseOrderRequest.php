<?php

namespace App\Http\Requests;

use App\Models\PurchaseOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (!$user) {
            return false;
        }

        // Ada PO di route = ubah draft, tidak ada = buat baru.
        $order = $this->route('purchase_order');

        $allowed = $order
            ? $user->can('update', $order)
            : $user->can('create', PurchaseOrder::class);

        if (!$allowed) {
            return false;
        }

        $warehouseId = (int) $this->input('warehouse_id');

        return $warehouseId === 0 || $user->canAccessWarehouse($warehouseId);
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'expected_date' => ['nullable', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('products', 'id')->where('is_active', true),
            ],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:999999999', 'decimal:0,3'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:999999999', 'decimal:0,4'],
        ];
    }

    public function attributes(): array
    {
        return [
            'supplier_id' => 'supplier',
            'warehouse_id' => 'gudang tujuan',
            'expected_date' => 'tanggal perkiraan tiba',
            'reference' => 'referensi',
            'notes' => 'catatan',
            'items' => 'item',
            'items.*.product_id' => 'produk',
            'items.*.quantity' => 'kuantitas',
            'items.*.unit_cost' => 'harga satuan',
        ];
    }
}
