<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $ids = $user->accessibleWarehouseIds(); // null = semua gudang

        $search = trim((string) $request->query('search', ''));
        $warehouseId = $request->integer('warehouse_id') ?: null;
        $categoryId = $request->integer('category_id') ?: null;
        $lowOnly = $request->boolean('low');

        $stocks = InventoryStock::query()
            ->with(['warehouse:id,code,name', 'product:id,sku,name,min_stock,category_id,unit_id', 'product.unit:id,symbol'])
            ->when($ids !== null, fn ($q) => $q->whereIn('inventory_stocks.warehouse_id', $ids))
            ->when($warehouseId, fn ($q) => $q->where('inventory_stocks.warehouse_id', $warehouseId))
            ->whereHas('product', function ($p) use ($search, $categoryId) {
                $p->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('sku', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")))
                    ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId));
            })
            ->when($lowOnly, fn ($q) => $q->whereHas('product', fn ($p) => $p
                ->whereColumn('inventory_stocks.quantity', '<=', 'products.min_stock')))
            ->orderBy('warehouse_id')
            ->orderBy('product_id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('inventory/index', [
            'stocks' => $stocks,
            'warehouses' => Warehouse::accessibleBy($user)->orderBy('code')->get(['id', 'code', 'name']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'filters' => [
                'search' => $search,
                'warehouse_id' => $warehouseId ? (string) $warehouseId : 'all',
                'category_id' => $categoryId ? (string) $categoryId : 'all',
                'low' => $lowOnly,
            ],
        ]);
    }
}
