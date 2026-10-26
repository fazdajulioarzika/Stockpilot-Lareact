<?php

use App\Actions\Inventory\PostGoodsReceipt;
use App\Enums\DocumentStatus;
use App\Enums\MovementType;
use App\Enums\Role;
use App\Enums\StocktakeStatus;
use App\Models\Category;
use App\Models\GoodsReceipt;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\StockIssue;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class)->group('concurrency');

beforeEach(function () {
    if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
        $this->markTestSkipped('Tes konkurensi membutuhkan MySQL/MariaDB.');
    }

    // Tes ini mengosongkan tabel, jadi hanya boleh jalan di database tes.
    $database = (string) DB::connection()->getDatabaseName();

    if (!str_contains($database, 'test')) {
        throw new RuntimeException("Menolak menjalankan tes konkurensi di database '{$database}'.");
    }
});

function ccEnv(): array
{
    $connection = config('database.default');
    $c = config("database.connections.{$connection}");

    return [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => $connection,
        'DB_HOST' => (string) $c['host'],
        'DB_PORT' => (string) $c['port'],
        'DB_DATABASE' => (string) $c['database'],
        'DB_USERNAME' => (string) $c['username'],
        'DB_PASSWORD' => (string) $c['password'],
    ];
}

/**
 * Jalankan beberapa proses sekaligus dan tunggu semuanya selesai.
 *
 * @param array<int, array{0: string, 1: int, 2: int}> $jobs [jenis, id dokumen, id user]
 * @return array<int, array{ok: bool, error?: string, message?: string}>
 */
function ccRun(array $jobs): array
{
    $startAt = sprintf('%.6F', microtime(true) + 6);
    $script = base_path('tests/Support/concurrent_post.php');
    $processes = [];

    foreach ($jobs as $i => [$kind, $id, $userId]) {
        $process = new Process([PHP_BINARY, $script, $kind, (string) $id, (string) $userId, $startAt], base_path(), ccEnv());
        $process->setTimeout(90);
        $process->start();
        $processes[$i] = $process;
    }

    $results = [];

    foreach ($processes as $i => $process) {
        $process->wait();

        $lines = array_values(array_filter(array_map('trim', explode("\n", $process->getOutput()))));
        $decoded = $lines === [] ? null : json_decode(end($lines), true);

        $results[$i] = is_array($decoded)
            ? $decoded
            : ['ok' => false, 'error' => 'NoOutput', 'message' => $process->getErrorOutput() . $process->getOutput()];
    }

    return $results;
}

function ccWorld(): object
{
    $category = Category::create(['name' => 'Cat', 'slug' => 'cat']);
    $unit = Unit::create(['name' => 'Pieces', 'symbol' => 'pcs']);
    $make = fn (string $sku) => Product::create([
        'category_id' => $category->id,
        'unit_id' => $unit->id,
        'sku' => $sku,
        'name' => $sku,
    ]);

    $admin = User::factory()->create();
    $admin->forceFill(['role' => Role::Admin])->save();

    return (object) [
        'wh' => Warehouse::create(['code' => 'GD-A', 'name' => 'A']),
        'p1' => $make('SKU-1'),
        'p2' => $make('SKU-2'),
        'admin' => $admin->fresh(),
    ];
}

function ccReceipt(object $w, Warehouse $warehouse, Product $product, string $qty): GoodsReceipt
{
    $receipt = GoodsReceipt::create(['warehouse_id' => $warehouse->id, 'created_by' => $w->admin->id]);
    $receipt->items()->create(['product_id' => $product->id, 'quantity' => $qty]);

    return $receipt;
}

function ccSeed(object $w, Warehouse $warehouse, Product $product, string $qty): void
{
    app(PostGoodsReceipt::class)->execute(ccReceipt($w, $warehouse, $product, $qty), $w->admin);
}

/** @param array<int, array{0: Product, 1: string}> $lines */
function ccIssue(object $w, array $lines): StockIssue
{
    $issue = StockIssue::create(['warehouse_id' => $w->wh->id, 'reason' => 'Uji', 'created_by' => $w->admin->id]);

    foreach ($lines as [$product, $qty]) {
        $issue->items()->create(['product_id' => $product->id, 'quantity' => $qty]);
    }

    return $issue;
}

function ccBalance(Warehouse $warehouse, Product $product): float
{
    return (float) InventoryStock::where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->value('quantity');
}

it('lets only one of two competing issues take the last stock', function () {
    $w = ccWorld();
    ccSeed($w, $w->wh, $w->p1, '10');

    $a = ccIssue($w, [[$w->p1, '8']]);
    $b = ccIssue($w, [[$w->p1, '8']]);

    $results = collect(ccRun([['issue', $a->id, $w->admin->id], ['issue', $b->id, $w->admin->id]]));

    expect($results->where('ok', true))->toHaveCount(1)
        ->and($results->where('ok', false))->toHaveCount(1)
        ->and($results->where('ok', false)->first()['error'])->toBe('InsufficientStockException')
        ->and(ccBalance($w->wh, $w->p1))->toBe(2.0)
        ->and(StockMovement::where('movement_type', MovementType::Issue->value)->count())->toBe(1)
        ->and(StockIssue::where('status', DocumentStatus::Posted->value)->count())->toBe(1)
        ->and(StockIssue::where('status', DocumentStatus::Draft->value)->count())->toBe(1);
});

it('never oversells when many issues compete for the same stock', function () {
    $w = ccWorld();
    ccSeed($w, $w->wh, $w->p1, '10');

    $jobs = [];

    foreach (range(1, 5) as $n) {
        $jobs[] = ['issue', ccIssue($w, [[$w->p1, '3']])->id, $w->admin->id];
    }

    $results = collect(ccRun($jobs));

    // 10 unit cukup untuk tepat 3 dokumen berisi 3 unit.
    expect($results->where('ok', true))->toHaveCount(3)
        ->and($results->where('ok', false))->toHaveCount(2)
        ->and($results->where('ok', false)->pluck('error')->unique()->all())->toBe(['InsufficientStockException'])
        ->and(ccBalance($w->wh, $w->p1))->toBe(1.0);

    $ledger = (float) StockMovement::where('product_id', $w->p1->id)->sum('quantity_delta');

    expect($ledger)->toBe(1.0);
});

it('posts one document only once when it is submitted twice at the same time', function () {
    $w = ccWorld();
    $receipt = ccReceipt($w, $w->wh, $w->p1, '5');

    $results = collect(ccRun([['receipt', $receipt->id, $w->admin->id], ['receipt', $receipt->id, $w->admin->id]]));

    expect($results->where('ok', true))->toHaveCount(1)
        ->and($results->where('ok', false)->first()['error'])->toBe('DocumentAlreadyPostedException')
        ->and(ccBalance($w->wh, $w->p1))->toBe(5.0)
        ->and(StockMovement::where('movement_type', MovementType::Receipt->value)->count())->toBe(1)
        ->and($receipt->fresh()->status)->toBe(DocumentStatus::Posted);
});

it('does not deadlock when documents list the same products in opposite order', function () {
    $w = ccWorld();
    ccSeed($w, $w->wh, $w->p1, '100');
    ccSeed($w, $w->wh, $w->p2, '100');

    $jobs = [];

    foreach (range(1, 2) as $n) {
        $jobs[] = ['issue', ccIssue($w, [[$w->p1, '1'], [$w->p2, '1']])->id, $w->admin->id];
        $jobs[] = ['issue', ccIssue($w, [[$w->p2, '1'], [$w->p1, '1']])->id, $w->admin->id];
    }

    $results = collect(ccRun($jobs));

    expect($results->where('ok', false)->all())->toBe([])
        ->and(ccBalance($w->wh, $w->p1))->toBe(96.0)
        ->and(ccBalance($w->wh, $w->p2))->toBe(96.0);
});

it('never lets a posting slip between the stocktake snapshot and the lock', function () {
    $w = ccWorld();

    // Beberapa putaran karena hasilnya bergantung pada siapa yang lebih dulu mendapat kunci.
    foreach (range(1, 3) as $round) {
        $warehouse = Warehouse::create(['code' => "GD-R{$round}", 'name' => "R{$round}"]);
        ccSeed($w, $warehouse, $w->p1, '10');

        $receipt = ccReceipt($w, $warehouse, $w->p1, '5');
        $stocktake = Stocktake::create(['warehouse_id' => $warehouse->id, 'created_by' => $w->admin->id]);

        ccRun([['receipt', $receipt->id, $w->admin->id], ['start_stocktake', $stocktake->id, $w->admin->id]]);

        $balance = ccBalance($warehouse, $w->p1);
        $snapshot = (float) $stocktake->items()->first()->system_qty;

        // Penerimaan sah sebelum snapshot (15) atau ditolak karena gudang terkunci (10).
        // Yang tidak boleh terjadi: snapshot berbeda dari saldo akhir.
        expect($snapshot)->toBe($balance)
            ->and(in_array($balance, [10.0, 15.0], true))->toBeTrue()
            ->and($stocktake->fresh()->status)->toBe(StocktakeStatus::Counting);
    }
});
function ccPurchaseOrder(object $w, string $qty): PurchaseOrder
{
    $supplier = Supplier::create(['name' => 'Pemasok Uji']);
    $order = PurchaseOrder::create(['supplier_id' => $supplier->id, 'warehouse_id' => $w->wh->id, 'created_by' => $w->admin->id]);
    $order->items()->create(['product_id' => $w->p1->id, 'quantity' => $qty, 'unit_cost' => '100']);
    $order->forceFill(['status' => PurchaseOrderStatus::Approved])->save();

    return $order;
}

function ccOrderReceipt(object $w, PurchaseOrder $order, string $qty): GoodsReceipt
{
    $receipt = GoodsReceipt::create([
        'warehouse_id' => $w->wh->id,
        'supplier_id' => $order->supplier_id,
        'purchase_order_id' => $order->id,
        'created_by' => $w->admin->id,
    ]);
    $receipt->items()->create(['product_id' => $w->p1->id, 'quantity' => $qty, 'unit_cost' => '100']);

    return $receipt;
}

it('never receives more than the order even when two receipts race for the same lines', function () {
    $w = ccWorld();
    $order = ccPurchaseOrder($w, '10');
    $a = ccOrderReceipt($w, $order, '6');
    $b = ccOrderReceipt($w, $order, '6');

    $results = collect(ccRun([['receipt', $a->id, $w->admin->id], ['receipt', $b->id, $w->admin->id]]));

    expect($results->where('ok', true))->toHaveCount(1)
        ->and($results->where('ok', false))->toHaveCount(1)
        ->and($results->where('ok', false)->first()['error'])->toBe('DomainException')
        ->and((float) $order->items()->first()->qty_received)->toBe(6.0)
        ->and(ccBalance($w->wh, $w->p1))->toBe(6.0)
        ->and($order->fresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived);
});

it('does not deadlock when a receipt is reversed while another receipt of the same order posts', function () {
    $w = ccWorld();
    $order = ccPurchaseOrder($w, '20');
    $first = ccOrderReceipt($w, $order, '6');
    $second = ccOrderReceipt($w, $order, '6');

    app(PostGoodsReceipt::class)->execute($first, $w->admin);

    $results = collect(ccRun([['reverse', $first->id, $w->admin->id], ['receipt', $second->id, $w->admin->id]]));

    expect($results->where('ok', false)->all())->toBe([])
        ->and((float) $order->items()->first()->qty_received)->toBe(6.0)
        ->and(ccBalance($w->wh, $w->p1))->toBe(6.0)
        ->and($order->fresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived);
});
