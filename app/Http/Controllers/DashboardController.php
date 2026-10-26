<?php

namespace App\Http\Controllers;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Enums\StocktakeStatus;
use App\Enums\TransferStatus;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $ids = $user->accessibleWarehouseIds(); // null = semua gudang

        // Batasi query ke gudang user. Kolom disebut lengkap agar aman saat ada join.
        $scoped = fn ($query, string $column) => $ids === null ? $query : $query->whereIn($column, $ids);

        $stockQuery = fn () => $scoped(InventoryStock::query(), 'inventory_stocks.warehouse_id');
        $lowQuery = fn () => $scoped(
            InventoryStock::query()
                ->join('products', 'products.id', '=', 'inventory_stocks.product_id')
                ->whereColumn('inventory_stocks.quantity', '<=', 'products.min_stock'),
            'inventory_stocks.warehouse_id',
        );

        $totalQty = $stockQuery()->sum('inventory_stocks.quantity');

        // Grafik 14 hari: hanya barang masuk (receipt) dan barang keluar (issue).
        $days = collect(range(13, 0))->map(fn (int $n) => Carbon::today()->subDays($n));

        $rows = $scoped(
            StockMovement::query()
                ->whereIn('movement_type', [MovementType::Receipt->value, MovementType::Issue->value])
                ->where('created_at', '>=', $days->first()->copy()->startOfDay()),
            'warehouse_id',
        )
            ->selectRaw('DATE(created_at) as d')
            ->selectRaw('SUM(CASE WHEN quantity_delta > 0 THEN quantity_delta ELSE 0 END) as masuk')
            ->selectRaw('SUM(CASE WHEN quantity_delta < 0 THEN -quantity_delta ELSE 0 END) as keluar')
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        $chart = $days->map(fn (Carbon $d) => [
            'date' => $d->toDateString(),
            'label' => $d->format('d/m'),
            'masuk' => bcadd((string) ($rows->get($d->toDateString())?->masuk ?? 0), '0', 3),
            'keluar' => bcadd((string) ($rows->get($d->toDateString())?->keluar ?? 0), '0', 3),
        ])->values()->all();

        $lowStock = $lowQuery()
            ->select('inventory_stocks.*')
            ->with(['warehouse:id,code', 'product:id,sku,name,min_stock,unit_id', 'product.unit:id,symbol'])
            ->orderByRaw('(inventory_stocks.quantity - products.min_stock) asc')
            ->limit(5)
            ->get()
            ->map(fn (InventoryStock $s) => [
                'id' => $s->id,
                'quantity' => (string) $s->quantity,
                'warehouse' => $s->warehouse,
                'product' => $s->product,
            ])->all();

        $recent = $scoped(
            StockMovement::query()->with(['warehouse:id,code', 'product:id,sku,name', 'creator:id,name']),
            'warehouse_id',
        )
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (StockMovement $m) => [
                'id' => $m->id,
                'created_at' => $m->created_at,
                'movement_type' => $m->movement_type->value,
                'quantity_delta' => (string) $m->quantity_delta,
                'warehouse' => $m->warehouse,
                'product' => $m->product,
                'creator' => $m->creator,
            ])->all();

        // Pekerjaan yang menunggu tindakan user ini.
        $attention = [];
        $add = function (string $label, int $count, string $href) use (&$attention) {
            if ($count > 0) {
                $attention[] = ['label' => $label, 'count' => $count, 'href' => $href];
            }
        };

        if ($user->hasRole(Role::Admin, Role::Manager)) {
            // Admin dan Manager memiliki akses semua gudang, jadi tidak perlu scope.
            $add('Transfer menunggu approval', StockTransfer::where('status', TransferStatus::PendingApproval->value)->count(), '/transfers?status=pending_approval');
            $add('Opname menunggu approval', Stocktake::where('status', StocktakeStatus::PendingApproval->value)->count(), '/stocktakes?status=pending_approval');
        }

        if ($user->hasRole(Role::Admin, Role::WarehouseStaff)) {
            $add(
                'Transfer siap dikirim',
                $scoped(StockTransfer::query()->where('status', TransferStatus::Approved->value), 'source_warehouse_id')->count(),
                '/transfers?status=approved',
            );
            $add(
                'Transfer dalam perjalanan ke gudang Anda',
                $scoped(
                    StockTransfer::query()->whereIn('status', [TransferStatus::InTransit->value, TransferStatus::PartiallyReceived->value]),
                    'destination_warehouse_id',
                )->count(),
                '/transfers',
            );
            $add(
                'Opname sedang dihitung',
                $scoped(Stocktake::query()->where('status', StocktakeStatus::Counting->value), 'warehouse_id')->count(),
                '/stocktakes?status=counting',
            );
        }

        $scope = $ids === null
            ? 'Semua gudang'
            : (Warehouse::whereIn('id', $ids)->orderBy('code')->pluck('code')->join(', ') ?: 'Belum ditugaskan ke gudang');

        return Inertia::render('dashboard', [
            'scope' => $scope,
            'kpi' => [
                'total_sku' => Product::where('is_active', true)->count(),
                'total_quantity' => bcadd((string) ($totalQty ?? 0), '0', 3),
                'stock_rows' => $stockQuery()->where('inventory_stocks.quantity', '>', 0)->count(),
                'low_stock' => $lowQuery()->count(),
            ],
            'chart' => $chart,
            'lowStock' => $lowStock,
            'recent' => $recent,
            'attention' => $attention,
        ]);
    }
}
