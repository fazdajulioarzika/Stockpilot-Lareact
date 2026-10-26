<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\Warehouse;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ValuationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $ids = $user->accessibleWarehouseIds(); // null = semua gudang

        $search = trim((string) $request->query('search', ''));
        $warehouseId = $request->integer('warehouse_id') ?: null;
        $categoryId = $request->integer('category_id') ?: null;

        $base = fn () => InventoryStock::query()
            ->join('products', 'products.id', '=', 'inventory_stocks.product_id')
            ->where('inventory_stocks.quantity', '>', 0)
            ->when($ids !== null, fn ($q) => $q->whereIn('inventory_stocks.warehouse_id', $ids))
            ->when($warehouseId, fn ($q) => $q->where('inventory_stocks.warehouse_id', $warehouseId))
            ->when($categoryId, fn ($q) => $q->where('products.category_id', $categoryId))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('products.sku', 'like', "%{$search}%")
                ->orWhere('products.name', 'like', "%{$search}%")));

        $stocks = $base()
            ->select('inventory_stocks.*')
            ->with(['warehouse:id,code', 'product:id,sku,name,unit_id', 'product.unit:id,symbol'])
            ->orderBy('inventory_stocks.warehouse_id')
            ->orderBy('products.name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (InventoryStock $s) => [
                'id' => $s->id,
                'warehouse' => $s->warehouse,
                'product' => $s->product,
                'quantity' => (string) $s->quantity,
                'avg_cost' => Money::round4(bcdiv((string) $s->total_value, (string) $s->quantity, 8)),
                'total_value' => (string) $s->total_value,
                'no_cost' => bccomp((string) $s->total_value, '0', 4) === 0,
            ]);

        $sums = $base()
            ->selectRaw('inventory_stocks.warehouse_id as wid, SUM(inventory_stocks.total_value) as total')
            ->groupBy('inventory_stocks.warehouse_id')
            ->pluck('total', 'wid');

        $codes = Warehouse::whereIn('id', $sums->keys())->pluck('code', 'id');

        return Inertia::render('valuation/index', [
            'stocks' => $stocks,
            'totals' => [
                'value' => bcadd((string) ($base()->sum('inventory_stocks.total_value') ?? 0), '0', 4),
                'rows' => $base()->count(),
                'missing_cost' => $base()->where('inventory_stocks.total_value', '=', 0)->count(),
            ],
            'byWarehouse' => $sums
                ->map(fn ($v, $id) => ['code' => $codes[$id] ?? '?', 'value' => bcadd((string) $v, '0', 4)])
                ->sortBy('code')
                ->values()
                ->all(),
            'warehouses' => Warehouse::accessibleBy($user)->orderBy('code')->get(['id', 'code', 'name']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'filters' => [
                'search' => $search,
                'warehouse_id' => $warehouseId ? (string) $warehouseId : 'all',
                'category_id' => $categoryId ? (string) $categoryId : 'all',
            ],
        ]);
    }
}
