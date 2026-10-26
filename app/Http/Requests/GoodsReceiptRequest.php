<?php

namespace App\Http\Requests;

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use Illuminate\Validation\Rule;

class GoodsReceiptRequest extends StockDocumentRequest
{
    private ?PurchaseOrder $order = null;

    protected function documentClass(): string
    {
        return GoodsReceipt::class;
    }

    protected function routeParameter(): string
    {
        return 'goods_receipt';
    }

    protected function extraRules(): array
    {
        return [
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:999999999', 'decimal:0,4'],
        ];
    }

    protected function extraAttributes(): array
    {
        return ['supplier_id' => 'supplier', 'items.*.unit_cost' => 'harga satuan'];
    }

    /** PO tempat draft ini berasal, jika ada. Pembuatan baru lewat form tidak pernah terhubung ke PO. */
    private function order(): ?PurchaseOrder
    {
        $receipt = $this->route('goods_receipt');

        if (!$receipt instanceof GoodsReceipt || !$receipt->purchase_order_id) {
            return null;
        }

        return $this->order ??= $receipt->purchaseOrder()->with('items')->first();
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            $order = $this->order();

            if (!$order) {
                return;
            }

            $lines = $order->items->keyBy('product_id');

            foreach ((array) $this->input('items', []) as $i => $line) {
                $poItem = $lines->get((int) ($line['product_id'] ?? 0));

                if (!$poItem) {
                    $validator->errors()->add("items.{$i}.product_id", "Produk ini tidak ada di Purchase Order {$order->number}.");

                    continue;
                }

                $qty = (string) ($line['quantity'] ?? '');

                if (preg_match('/^\d{1,12}(\.\d{1,3})?$/', $qty) === 1 && bccomp($qty, $poItem->outstanding(), 3) > 0) {
                    $validator->errors()->add("items.{$i}.quantity", "Melebihi sisa pesanan ({$poItem->outstanding()}).");
                }
            }
        });
    }

    public function validated($key = null, $default = null)
    {
        $data = parent::validated();

        if ($order = $this->order()) {
            $costs = $order->items->pluck('unit_cost', 'product_id');

            $data['warehouse_id'] = $order->warehouse_id;
            $data['supplier_id'] = $order->supplier_id;
            $data['items'] = array_map(fn ($line) => [
                ...$line,
                'unit_cost' => (string) ($costs[(int) $line['product_id']] ?? $line['unit_cost']),
            ], $data['items']);
        }

        return $key === null ? $data : data_get($data, $key, $default);
    }
}
