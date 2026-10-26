<?php

namespace App\Http\Controllers;

use App\Enums\MovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockMovementController extends Controller
{
    private const DOCUMENT_ROUTES = [
        'App\Models\GoodsReceipt' => '/goods-receipts',
        'App\Models\StockIssue' => '/stock-issues',
        'App\Models\StockTransfer' => '/transfers',
        'App\Models\Stocktake' => '/stocktakes',
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $ids = $user->accessibleWarehouseIds();

        $type = MovementType::tryFrom((string) $request->query('type'))?->value;
        $warehouseId = $request->integer('warehouse_id') ?: null;
        $productId = $request->integer('product_id') ?: null;
        $from = $request->date('from')?->startOfDay();
        $to = $request->date('to')?->endOfDay();

        $movements = StockMovement::query()
            ->with(['warehouse:id,code', 'product:id,sku,name', 'creator:id,name'])
            ->when($ids !== null, fn ($q) => $q->whereIn('warehouse_id', $ids))
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($productId, fn ($q) => $q->where('product_id', $productId))
            ->when($type, fn ($q) => $q->where('movement_type', $type))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (StockMovement $m) => [
                'id' => $m->id,
                'created_at' => $m->created_at,
                'movement_type' => $m->movement_type->value,
                'quantity_delta' => $m->quantity_delta,
                'warehouse' => $m->warehouse,
                'product' => $m->product,
                'creator' => $m->creator,
                'reference_url' => isset(self::DOCUMENT_ROUTES[$m->reference_type])
                    ? self::DOCUMENT_ROUTES[$m->reference_type] . '/' . $m->reference_id
                    : null,
            ]);

        return Inertia::render('stock-movements/index', [
            'movements' => $movements,
            'warehouses' => Warehouse::accessibleBy($user)->orderBy('code')->get(['id', 'code', 'name']),
            'products' => Product::orderBy('name')->get(['id', 'sku', 'name']),
            'types' => array_map(fn (MovementType $t) => $t->value, MovementType::cases()),
            'filters' => [
                'warehouse_id' => $warehouseId ? (string) $warehouseId : 'all',
                'product_id' => $productId ? (string) $productId : 'all',
                'type' => $type ?? 'all',
                'from' => $request->query('from', ''),
                'to' => $request->query('to', ''),
            ],
        ]);
    }
}
