<?php

namespace App\Http\Controllers;

use App\Enums\MovementType;
use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\InventoryStock;
use App\Models\StockIssue;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\User;
use App\Support\CsvExport;
use App\Support\Money;
use App\Support\Period;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    private const TYPE_LABEL = [
        'receipt' => 'Barang masuk',
        'issue' => 'Barang keluar',
        'transfer_out' => 'Transfer keluar',
        'transfer_in' => 'Transfer masuk',
        'transfer_return' => 'Retur transfer',
        'adjustment' => 'Penyesuaian',
        'reversal' => 'Pembalikan',
        'revaluation' => 'Penilaian ulang',
    ];

    private const DOCUMENTS = [GoodsReceipt::class, StockIssue::class, StockTransfer::class, Stocktake::class];

    private function audit(Request $request, string $report): void
    {
        $user = $request->user();

        $filters = array_filter(
            $request->only(['search', 'warehouse_id', 'category_id', 'low', 'from', 'to', 'type', 'product_id']),
            fn ($v) => is_scalar($v) && $v !== '',
        );

        AuditLog::record('report.exported', $user, $user, [
            'report' => $report,
            'format' => CsvExport::international($request) ? 'intl' : 'id',
            ...($filters === [] ? [] : ['filters' => $filters]),
        ]);
    }

    private function filename(string $name): string
    {
        return 'stockpilot-' . $name . '-' . now()->format('Ymd') . '.csv';
    }

    /** Filter yang sama dengan halaman Inventory dan Nilai Persediaan. */
    private function stockQuery(Request $request, User $user, bool $onlyPositive)
    {
        $ids = $user->accessibleWarehouseIds(); // null = semua gudang
        $search = trim((string) $request->query('search', ''));
        $warehouseId = $request->integer('warehouse_id') ?: null;
        $categoryId = $request->integer('category_id') ?: null;
        $low = $request->boolean('low');

        return InventoryStock::query()
            ->when($onlyPositive, fn ($q) => $q->where('quantity', '>', 0))
            ->when($ids !== null, fn ($q) => $q->whereIn('warehouse_id', $ids))
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->whereHas('product', function ($p) use ($search, $categoryId) {
                $p->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('sku', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")))
                    ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId));
            })
            ->when($low, fn ($q) => $q->whereHas('product', fn ($p) => $p
                ->whereColumn('inventory_stocks.quantity', '<=', 'products.min_stock')));
    }

    public function inventory(Request $request): StreamedResponse
    {
        $this->audit($request, 'inventory');

        $query = $this->stockQuery($request, $request->user(), false)->with([
            'warehouse:id,code',
            'product:id,sku,name,min_stock,category_id,unit_id',
            'product.category:id,name',
            'product.unit:id,symbol',
        ]);

        return CsvExport::download(
            $this->filename('inventory'),
            ['Gudang', 'SKU', 'Produk', 'Kategori', 'Stok', 'Satuan', 'Stok minimum', 'Status'],
            function (Closure $emit) use ($query) {
                foreach ($query->lazyById(1000) as $s) {
                    $low = bccomp((string) $s->quantity, (string) $s->product->min_stock, 3) <= 0;

                    $emit([
                        $s->warehouse->code,
                        $s->product->sku,
                        $s->product->name,
                        $s->product->category?->name,
                        CsvExport::num($s->quantity),
                        $s->product->unit?->symbol,
                        CsvExport::num($s->product->min_stock),
                        $low ? 'Stok rendah' : 'Aman',
                    ]);
                }
            },
            CsvExport::international($request),
        );
    }

    /** Hanya Admin dan Manager (dijaga middleware di route). */
    public function valuation(Request $request): StreamedResponse
    {
        $this->audit($request, 'valuation');

        $query = $this->stockQuery($request, $request->user(), true)->with([
            'warehouse:id,code',
            'product:id,sku,name,unit_id',
            'product.unit:id,symbol',
        ]);

        return CsvExport::download(
            $this->filename('nilai-persediaan'),
            ['Gudang', 'SKU', 'Produk', 'Stok', 'Satuan', 'Biaya rata-rata', 'Nilai', 'Keterangan'],
            function (Closure $emit) use ($query) {
                foreach ($query->lazyById(1000) as $s) {
                    $noCost = bccomp((string) $s->total_value, '0', 4) === 0;

                    $emit([
                        $s->warehouse->code,
                        $s->product->sku,
                        $s->product->name,
                        CsvExport::num($s->quantity),
                        $s->product->unit?->symbol,
                        CsvExport::num(Money::round4(bcdiv((string) $s->total_value, (string) $s->quantity, 8))),
                        CsvExport::num($s->total_value),
                        $noCost ? 'Tanpa biaya' : '',
                    ]);
                }
            },
            CsvExport::international($request),
        );
    }

    public function movements(Request $request): StreamedResponse
    {
        $user = $request->user();
        $this->audit($request, 'stock-movements');

        $ids = $user->accessibleWarehouseIds();
        $canValue = $user->can('view-valuation');
        $type = MovementType::tryFrom((string) $request->query('type'))?->value;
        $warehouseId = $request->integer('warehouse_id') ?: null;
        $productId = $request->integer('product_id') ?: null;
        $from = Period::date($request->query('from'))?->startOfDay();
        $to = Period::date($request->query('to'))?->endOfDay();

        $query = StockMovement::query()
            ->with(['warehouse:id,code', 'product:id,sku,name', 'creator:id,name'])
            ->when($ids !== null, fn ($q) => $q->whereIn('warehouse_id', $ids))
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($productId, fn ($q) => $q->where('product_id', $productId))
            ->when($type, fn ($q) => $q->where('movement_type', $type))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));

        $headers = ['Waktu', 'Gudang', 'SKU', 'Produk', 'Tipe', 'Perubahan'];

        if ($canValue) {
            $headers[] = 'Nilai';
        }

        array_push($headers, 'Dokumen', 'Oleh');

        return CsvExport::download(
            $this->filename('stock-movements'),
            $headers,
            function (Closure $emit) use ($query, $canValue) {
                $query->chunkById(1000, function ($rows) use ($emit, $canValue) {
                    $numbers = $this->documentNumbers($rows);

                    foreach ($rows as $m) {
                        $row = [
                            $m->created_at?->format('Y-m-d H:i:s'),
                            $m->warehouse->code,
                            $m->product->sku,
                            $m->product->name,
                            self::TYPE_LABEL[$m->movement_type->value] ?? $m->movement_type->value,
                            CsvExport::num($m->quantity_delta),
                        ];

                        if ($canValue) {
                            $row[] = CsvExport::num($m->value_delta);
                        }

                        $row[] = $numbers[$m->reference_type][$m->reference_id]
                            ?? class_basename($m->reference_type) . ' #' . $m->reference_id;
                        $row[] = $m->creator->name;

                        $emit($row);
                    }
                });
            },
            CsvExport::international($request),
        );
    }

    /** @return array<string, array<int, string>> tipe dokumen => id => nomor */
    private function documentNumbers(Collection $rows): array
    {
        $numbers = [];

        foreach ($rows->groupBy('reference_type') as $type => $group) {
            if (in_array($type, self::DOCUMENTS, true)) {
                $numbers[$type] = $type::query()
                    ->whereIn('id', $group->pluck('reference_id')->unique())
                    ->pluck('number', 'id')
                    ->all();
            }
        }

        return $numbers;
    }
}
