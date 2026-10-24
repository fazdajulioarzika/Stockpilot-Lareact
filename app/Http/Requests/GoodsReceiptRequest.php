<?php

namespace App\Http\Requests;

use App\Models\GoodsReceipt;
use Illuminate\Validation\Rule;

class GoodsReceiptRequest extends StockDocumentRequest
{
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
        ];
    }

    protected function extraAttributes(): array
    {
        return ['supplier_id' => 'supplier'];
    }
}
