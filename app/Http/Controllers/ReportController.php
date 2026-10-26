<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\StockIssue;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\CsvExport;
use App\Support\Period;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    private const DAILY_MAX_DAYS = 45;

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return [
            ...Period::resolve($request),
            'warehouse_id' => $request->integer('warehouse_id') ?: null,
            'category_id' => $request->integer('category_id') ?: null,
            'search' => trim((string) $request->query('search', '')),
        ];
    }

    /** Mutasi yang boleh dilihat user, dengan filter gudang dan produk. */
    private function scoped(User $user, array $f): Builder
    {
        $ids = $user->accessibleWarehouseIds(); // null = semua gudang

        return StockMovement::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('warehouse_id', $ids))
            ->when($f['warehouse_id'], fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($f['category_id'] || $f['search'] !== '', function ($q) use ($f) {
                $q->whereIn('product_id', Product::query()
                    ->select('id')
                    ->when($f['category_id'], fn ($p, $id) => $p->where('category_id', $id))
                    ->when($f['search'] !== '', fn ($p) => $p->where(fn ($w) => $w
                        ->where('sku', 'like', "%{$f['search']}%")
                        ->orWhere('name', 'like', "%{$f['search']}%"))));
            });
    }

    /** Satu baris per produk dan gudang: awal, masuk, keluar, nilai. Semuanya dari ledger. */
    private function aggregate(User $user, array $f): Builder
    {
        $from = $f['from']->startOfDay()->format('Y-m-d H:i:s');
        $to = $f['to']->endOfDay()->format('Y-m-d H:i:s');

        return $this->scoped($user, $f)
            ->where('created_at', '<=', $to)
            ->select('warehouse_id', 'product_id')
            ->selectRaw('SUM(CASE WHEN created_at < ? THEN quantity_delta ELSE 0 END) AS opening', [$from])
            ->selectRaw('SUM(CASE WHEN created_at >= ? AND quantity_delta > 0 THEN quantity_delta ELSE 0 END) AS in_qty', [$from])
            ->selectRaw('SUM(CASE WHEN created_at >= ? AND quantity_delta < 0 THEN -quantity_delta ELSE 0 END) AS out_qty', [$from])
            ->selectRaw('SUM(CASE WHEN created_at < ? THEN value_delta ELSE 0 END) AS opening_value', [$from])
            ->selectRaw('SUM(value_delta) AS closing_value')
            ->groupBy('warehouse_id', 'product_id');
    }

    private function outer(Builder $aggregate)
    {
        // Hanya baris yang punya saldo awal atau pergerakan pada periode.
        return DB::query()->fromSub($aggregate, 'r')->where(function ($q) {
            $q->where('opening', '<>', 0)->orWhere('in_qty', '<>', 0)->orWhere('out_qty', '<>', 0);
        });
    }

    private function n3(mixed $v): string
    {
        return bcadd((string) ($v ?? 0), '0', 3);
    }

    private function n4(mixed $v): string
    {
        return bcadd((string) ($v ?? 0), '0', 4);
    }

    /** @return array{granularity: string, points: array<int, array{key: string, label: string, masuk: string, keluar: string}>} */
    private function trend(User $user, array $f): array
    {
        $from = $f['from']->startOfDay();
        $to = $f['to']->endOfDay();
        $monthly = ((int) $f['from']->diffInDays($f['to']) + 1) > self::DAILY_MAX_DAYS;
        $key = $monthly ? "DATE_FORMAT(created_at, '%Y-%m')" : 'DATE(created_at)';

        // Masuk dan keluar bersih dari pembalikan. Transfer dan penyesuaian tidak dihitung.
        $rows = $this->scoped($user, $f)
            ->whereBetween('created_at', [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')])
            ->whereIn('movement_type', ['receipt', 'issue', 'reversal'])
            ->selectRaw("{$key} AS k")
            ->selectRaw("SUM(CASE WHEN movement_type = 'receipt' THEN quantity_delta WHEN movement_type = 'reversal' AND reference_type = ? THEN quantity_delta ELSE 0 END) AS masuk", [GoodsReceipt::class])
            ->selectRaw("SUM(CASE WHEN movement_type = 'issue' THEN -quantity_delta WHEN movement_type = 'reversal' AND reference_type = ? THEN -quantity_delta ELSE 0 END) AS keluar", [StockIssue::class])
            ->groupBy('k')
            ->get()
            ->keyBy('k');

        $points = [];

        if ($monthly) {
            for ($m = $f['from']->startOfMonth(); $m <= $f['to']; $m = $m->addMonth()) {
                $k = $m->format('Y-m');
                $points[] = [
                    'key' => $k,
                    'label' => $m->format('m/Y'),
                    'masuk' => $this->n3($rows->get($k)?->masuk),
                    'keluar' => $this->n3($rows->get($k)?->keluar),
                ];
            }
        } else {
            for ($d = $f['from']; $d <= $f['to']; $d = $d->addDay()) {
                $k = $d->toDateString();
                $points[] = [
                    'key' => $k,
                    'label' => $d->format('d/m'),
                    'masuk' => $this->n3($rows->get($k)?->masuk),
                    'keluar' => $this->n3($rows->get($k)?->keluar),
                ];
            }
        }

        return ['granularity' => $monthly ? 'month' : 'day', 'points' => $points];
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $f = $this->filters($request);
        $canValue = $user->can('view-valuation');
        $aggregate = $this->aggregate($user, $f);

        $page = $this->outer($aggregate)
            ->orderBy('warehouse_id')
            ->orderBy('product_id')
            ->paginate(15)
            ->withQueryString();

        $items = collect($page->items());
        $products = Product::with('unit:id,symbol')
            ->whereIn('id', $items->pluck('product_id'))
            ->get(['id', 'sku', 'name', 'unit_id'])
            ->keyBy('id');
        $warehouses = Warehouse::whereIn('id', $items->pluck('warehouse_id'))->get(['id', 'code'])->keyBy('id');

        $page->through(function ($r) use ($products, $warehouses, $canValue) {
            $opening = $this->n3($r->opening);
            $inflow = $this->n3($r->in_qty);
            $outflow = $this->n3($r->out_qty);

            return [
                'warehouse' => ['code' => $warehouses->get($r->warehouse_id)?->code ?? '?'],
                'product' => $products->get($r->product_id),
                'opening' => $opening,
                'inflow' => $inflow,
                'outflow' => $outflow,
                'closing' => bcsub(bcadd($opening, $inflow, 3), $outflow, 3),
                ...($canValue ? [
                    'opening_value' => $this->n4($r->opening_value),
                    'closing_value' => $this->n4($r->closing_value),
                ] : []),
            ];
        });

        $sum = $this->outer($aggregate)->selectRaw(
            'COUNT(*) AS rows_count, COALESCE(SUM(in_qty), 0) AS total_in, COALESCE(SUM(out_qty), 0) AS total_out, '
                . 'COALESCE(SUM(opening_value), 0) AS total_opening_value, COALESCE(SUM(closing_value), 0) AS total_closing_value'
        )->first();

        return Inertia::render('reports/index', [
            'rows' => $page,
            'totals' => [
                'rows' => (int) $sum->rows_count,
                'inflow' => $this->n3($sum->total_in),
                'outflow' => $this->n3($sum->total_out),
                ...($canValue ? [
                    'opening_value' => $this->n4($sum->total_opening_value),
                    'closing_value' => $this->n4($sum->total_closing_value),
                ] : []),
            ],
            'trend' => $this->trend($user, $f),
            'filters' => [
                'from' => $f['from']->toDateString(),
                'to' => $f['to']->toDateString(),
                'warehouse_id' => $f['warehouse_id'] ? (string) $f['warehouse_id'] : 'all',
                'category_id' => $f['category_id'] ? (string) $f['category_id'] : 'all',
                'search' => $f['search'],
            ],
            'warehouses' => Warehouse::accessibleBy($user)->orderBy('code')->get(['id', 'code', 'name']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'canValue' => $canValue,
            'error' => $f['error'],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        $f = $this->filters($request);
        $canValue = $user->can('view-valuation');

        AuditLog::record('report.exported', $user, $user, [
            'report' => 'movements',
            'format' => CsvExport::international($request) ? 'intl' : 'id',
            'from' => $f['from']->toDateString(),
            'to' => $f['to']->toDateString(),
        ]);

        $query = $this->outer($this->aggregate($user, $f))->orderBy('warehouse_id')->orderBy('product_id');
        $headers = ['Gudang', 'SKU', 'Produk', 'Satuan', 'Stok awal', 'Masuk', 'Keluar', 'Stok akhir'];

        if ($canValue) {
            array_push($headers, 'Nilai awal', 'Nilai akhir');
        }

        return CsvExport::download(
            'stockpilot-laporan-mutasi-' . $f['from']->format('Ymd') . '-' . $f['to']->format('Ymd') . '.csv',
            $headers,
            function (Closure $emit) use ($query, $canValue) {
                $products = Product::with('unit:id,symbol')->get(['id', 'sku', 'name', 'unit_id'])->keyBy('id');
                $warehouses = Warehouse::pluck('code', 'id');

                foreach ($query->cursor() as $r) {
                    $opening = $this->n3($r->opening);
                    $inflow = $this->n3($r->in_qty);
                    $outflow = $this->n3($r->out_qty);
                    $product = $products->get($r->product_id);

                    $row = [
                        $warehouses[$r->warehouse_id] ?? '',
                        $product?->sku,
                        $product?->name,
                        $product?->unit?->symbol,
                        CsvExport::num($opening),
                        CsvExport::num($inflow),
                        CsvExport::num($outflow),
                        CsvExport::num(bcsub(bcadd($opening, $inflow, 3), $outflow, 3)),
                    ];

                    if ($canValue) {
                        array_push($row, CsvExport::num($this->n4($r->opening_value)), CsvExport::num($this->n4($r->closing_value)));
                    }

                    $emit($row);
                }
            },
            CsvExport::international($request),
        );
    }
}
