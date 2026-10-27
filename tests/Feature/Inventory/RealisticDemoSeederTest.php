<?php

use App\Enums\Role;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\InventoryStock;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\User;
use Database\Seeders\RealisticDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('builds a consistent history without breaking any invariant', function () {
    foreach ([Role::Admin, Role::Manager] as $role) {
        User::factory()->create()->forceFill(['role' => $role])->save();
    }

    RealisticDemoSeeder::$days = 21;

    try {
        $this->seed(RealisticDemoSeeder::class);
    } finally {
        RealisticDemoSeeder::$days = 90;
    }

    expect(StockMovement::count())->toBeGreaterThan(50)
        ->and(InventoryStock::where('quantity', '<', 0)->count())->toBe(0)
        ->and(StockMovement::where('created_at', '>', now()->addMinutes(5))->count())->toBe(0)
        ->and(Stocktake::whereIn('status', ['counting', 'pending_approval'])->count())->toBe(0);

    // Saldo dan nilai selalu sama dengan jumlah ledger-nya.
    foreach (InventoryStock::all() as $stock) {
        $sums = StockMovement::where('warehouse_id', $stock->warehouse_id)
            ->where('product_id', $stock->product_id)
            ->selectRaw('COALESCE(SUM(quantity_delta), 0) AS q, COALESCE(SUM(value_delta), 0) AS v')
            ->first();

        expect(bccomp((string) $stock->quantity, bcadd((string) $sums->q, '0', 3), 3))->toBe(0)
            ->and(bccomp((string) $stock->total_value, bcadd((string) $sums->v, '0', 4), 4))->toBe(0);
    }

    // Jumlah diterima di PO sama dengan penerimaan yang diposting dan tidak dibalik.
    foreach (PurchaseOrderItem::all() as $line) {
        $received = GoodsReceiptItem::query()
            ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_items.goods_receipt_id')
            ->where('goods_receipts.purchase_order_id', $line->purchase_order_id)
            ->where('goods_receipts.status', 'posted')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('document_reversals')
                ->whereColumn('document_reversals.document_id', 'goods_receipts.id')
                ->where('document_reversals.document_type', GoodsReceipt::class))
            ->where('goods_receipt_items.product_id', $line->product_id)
            ->sum('goods_receipt_items.quantity');

        expect(bccomp((string) $line->qty_received, bcadd((string) $received, '0', 3), 3))->toBe(0)
            ->and(bccomp((string) $line->qty_received, (string) $line->quantity, 3))->toBeLessThanOrEqual(0);
    }
});
