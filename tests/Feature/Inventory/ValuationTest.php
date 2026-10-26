<?php

use App\Actions\Inventory\DispatchStockTransfer;
use App\Actions\Inventory\PostGoodsReceipt;
use App\Actions\Inventory\PostStockIssue;
use App\Actions\Inventory\PostStocktake;
use App\Actions\Inventory\ReceiveStockTransfer;
use App\Actions\Inventory\RecordStocktakeCount;
use App\Actions\Inventory\ReturnTransferRemainder;
use App\Actions\Inventory\ReverseStockDocument;
use App\Actions\Inventory\StartStocktake;
use App\Actions\Inventory\SubmitStockTransfer;
use App\Actions\Inventory\SubmitStocktakeForApproval;
use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\GoodsReceipt;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\StockIssue;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function vlUser(Role $role, array $warehouseIds = []): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();
    $user->warehouses()->attach($warehouseIds);

    return $user->fresh();
}

function vlWorld(): object
{
    config(['stockpilot.transfer_requires_approval' => false]);

    $category = Category::create(['name' => 'Cat', 'slug' => 'cat']);
    $unit = Unit::create(['name' => 'Pieces', 'symbol' => 'pcs']);
    $make = fn (string $sku) => Product::create([
        'category_id' => $category->id,
        'unit_id' => $unit->id,
        'sku' => $sku,
        'name' => $sku,
    ]);

    $a = Warehouse::create(['code' => 'GD-A', 'name' => 'A']);
    $b = Warehouse::create(['code' => 'GD-B', 'name' => 'B']);

    return (object) [
        'a' => $a,
        'b' => $b,
        'p1' => $make('SKU-1'),
        'p2' => $make('SKU-2'),
        'admin' => vlUser(Role::Admin),
        'manager' => vlUser(Role::Manager),
        'staff' => vlUser(Role::WarehouseStaff, [$a->id]),
    ];
}

function vlReceipt(object $w, Warehouse $warehouse, Product $product, string $qty, string $cost): GoodsReceipt
{
    $receipt = GoodsReceipt::create(['warehouse_id' => $warehouse->id, 'created_by' => $w->admin->id]);
    $receipt->items()->create(['product_id' => $product->id, 'quantity' => $qty, 'unit_cost' => $cost]);
    app(PostGoodsReceipt::class)->execute($receipt, $w->admin);

    return $receipt->fresh();
}

function vlIssue(object $w, Warehouse $warehouse, Product $product, string $qty): StockIssue
{
    $issue = StockIssue::create(['warehouse_id' => $warehouse->id, 'reason' => 'Penjualan', 'created_by' => $w->admin->id]);
    $issue->items()->create(['product_id' => $product->id, 'quantity' => $qty]);
    app(PostStockIssue::class)->execute($issue, $w->admin);

    return $issue->fresh();
}

function vlStock(Warehouse $warehouse, Product $product): InventoryStock
{
    return InventoryStock::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
}

function vlTransfer(object $w, Warehouse $from, Warehouse $to, Product $product, string $qty): StockTransfer
{
    $transfer = StockTransfer::create([
        'source_warehouse_id' => $from->id,
        'destination_warehouse_id' => $to->id,
        'created_by' => $w->admin->id,
    ]);
    $transfer->items()->create(['product_id' => $product->id, 'quantity' => $qty]);

    // Approval dimatikan di vlWorld(), jadi langsung approved.
    app(SubmitStockTransfer::class)->execute($transfer, $w->admin);
    app(DispatchStockTransfer::class)->execute($transfer->fresh(), $w->admin);

    return $transfer->fresh();
}

function vlReceive(StockTransfer $transfer, User $user, string $qty): void
{
    $item = $transfer->items()->first();

    app(ReceiveStockTransfer::class)->execute(
        $transfer->fresh(),
        $user,
        [['item_id' => $item->id, 'quantity' => $qty]],
        (string) Str::uuid(),
    );
}

it('values receipts at the entered cost and keeps a weighted average', function () {
    $w = vlWorld();
    vlReceipt($w, $w->a, $w->p1, '10', '100');
    vlReceipt($w, $w->a, $w->p1, '10', '200');

    $stock = vlStock($w->a, $w->p1);
    $first = StockMovement::where('movement_type', MovementType::Receipt->value)->orderBy('id')->first();

    expect((float) $stock->quantity)->toBe(20.0)
        ->and($stock->total_value)->toBe('3000.0000')
        ->and($first->value_delta)->toBe('1000.0000');
});

it('issues at the current average without changing it', function () {
    $w = vlWorld();
    vlReceipt($w, $w->a, $w->p1, '10', '100');
    vlReceipt($w, $w->a, $w->p1, '10', '200');
    vlIssue($w, $w->a, $w->p1, '5');

    $stock = vlStock($w->a, $w->p1);
    $movement = StockMovement::where('movement_type', MovementType::Issue->value)->first();

    expect((float) $stock->quantity)->toBe(15.0)
        ->and($stock->total_value)->toBe('2250.0000') // rata-rata tetap 150
        ->and($movement->value_delta)->toBe('-750.0000');
});

it('rounds half up and leaves exactly zero value when stock reaches zero', function () {
    $w = vlWorld();
    vlReceipt($w, $w->a, $w->p1, '3', '3.3333');

    expect(vlStock($w->a, $w->p1)->total_value)->toBe('9.9999');

    vlIssue($w, $w->a, $w->p1, '1');

    expect(vlStock($w->a, $w->p1)->total_value)->toBe('6.6666');

    vlIssue($w, $w->a, $w->p1, '2');

    $stock = vlStock($w->a, $w->p1);

    expect((float) $stock->quantity)->toBe(0.0)->and($stock->total_value)->toBe('0.0000');
});

it('carries value through a transfer, a partial receipt and the return of the rest', function () {
    $w = vlWorld();
    vlReceipt($w, $w->a, $w->p1, '10', '100');

    $transfer = vlTransfer($w, $w->a, $w->b, $w->p1, '4');

    expect($transfer->items()->first()->value_sent)->toBe('400.0000')
        ->and(vlStock($w->a, $w->p1)->total_value)->toBe('600.0000');

    vlReceive($transfer, $w->admin, '3');

    expect((float) vlStock($w->b, $w->p1)->quantity)->toBe(3.0)
        ->and(vlStock($w->b, $w->p1)->total_value)->toBe('300.0000');

    app(ReturnTransferRemainder::class)->execute($transfer->fresh(), $w->admin);

    $item = $transfer->items()->first();

    expect((float) vlStock($w->a, $w->p1)->quantity)->toBe(7.0)
        ->and(vlStock($w->a, $w->p1)->total_value)->toBe('700.0000')
        ->and($item->value_returned)->toBe('100.0000')
        ->and($item->remainingValue())->toBe('0.0000');
});

it('never loses a cent to rounding when a transfer is received in pieces', function () {
    $w = vlWorld();
    vlReceipt($w, $w->a, $w->p1, '3', '3.3333'); // nilai 9.9999

    $transfer = vlTransfer($w, $w->a, $w->b, $w->p1, '3');

    expect($transfer->items()->first()->value_sent)->toBe('9.9999');

    foreach (range(1, 3) as $n) {
        vlReceive($transfer, $w->admin, '1');
    }

    $item = $transfer->items()->first();

    expect((float) vlStock($w->b, $w->p1)->quantity)->toBe(3.0)
        ->and(vlStock($w->b, $w->p1)->total_value)->toBe('9.9999')
        ->and($item->value_received)->toBe('9.9999')
        ->and(vlStock($w->a, $w->p1)->total_value)->toBe('0.0000');
});

it('restores an issue at its original value and reverses a receipt at the current average', function () {
    $w = vlWorld();
    vlReceipt($w, $w->a, $w->p1, '10', '100');
    $issue = vlIssue($w, $w->a, $w->p1, '5');                 // nilai 500 tersisa, stok 5
    $receipt = vlReceipt($w, $w->a, $w->p1, '5', '300');      // stok 10, nilai 2000

    app(ReverseStockDocument::class)->execute($issue, $w->manager, 'Salah pelanggan');

    // Nilai asli 500 kembali, bukan 5 x rata-rata saat ini (1000).
    expect((float) vlStock($w->a, $w->p1)->quantity)->toBe(15.0)
        ->and(vlStock($w->a, $w->p1)->total_value)->toBe('2500.0000');

    app(ReverseStockDocument::class)->execute($receipt, $w->manager, 'Salah input');

    // 5 x rata-rata (2500 / 15) = 833.3333 keluar.
    expect((float) vlStock($w->a, $w->p1)->quantity)->toBe(10.0)
        ->and(vlStock($w->a, $w->p1)->total_value)->toBe('1666.6667');
});

it('values stocktake adjustments at the current average', function () {
    $w = vlWorld();
    vlReceipt($w, $w->a, $w->p1, '10', '100');
    vlReceipt($w, $w->a, $w->p2, '10', '50');

    $stocktake = Stocktake::create(['warehouse_id' => $w->a->id, 'created_by' => $w->admin->id]);
    app(StartStocktake::class)->execute($stocktake, $w->admin);

    $items = $stocktake->items()->get()->keyBy('product_id');

    app(RecordStocktakeCount::class)->execute($stocktake, $w->admin, [
        ['item_id' => $items[$w->p1->id]->id, 'counted_qty' => '8', 'reason' => 'rusak'],
        ['item_id' => $items[$w->p2->id]->id, 'counted_qty' => '12', 'reason' => 'ditemukan'],
    ]);
    app(SubmitStocktakeForApproval::class)->execute($stocktake, $w->admin);
    app(PostStocktake::class)->execute($stocktake, $w->manager);

    $delta = fn (Product $p) => StockMovement::where('movement_type', MovementType::Adjustment->value)
        ->where('product_id', $p->id)->value('value_delta');

    expect(vlStock($w->a, $w->p1)->total_value)->toBe('800.0000')
        ->and(vlStock($w->a, $w->p2)->total_value)->toBe('600.0000')
        ->and($delta($w->p1))->toBe('-200.0000')
        ->and($delta($w->p2))->toBe('100.0000');
});

it('keeps stock value reconcilable with the ledger', function () {
    $w = vlWorld();
    vlReceipt($w, $w->a, $w->p1, '10', '100');
    vlReceipt($w, $w->a, $w->p1, '7', '130.5');
    vlReceipt($w, $w->a, $w->p2, '3', '3.3333');
    vlIssue($w, $w->a, $w->p1, '4');
    $issue = vlIssue($w, $w->a, $w->p2, '1');

    $transfer = vlTransfer($w, $w->a, $w->b, $w->p1, '6');
    vlReceive($transfer, $w->admin, '2');
    vlReceive($transfer, $w->admin, '1');
    app(ReturnTransferRemainder::class)->execute($transfer->fresh(), $w->admin);

    app(ReverseStockDocument::class)->execute($issue, $w->manager, 'Salah pelanggan');

    foreach (InventoryStock::all() as $stock) {
        $ledger = StockMovement::where('warehouse_id', $stock->warehouse_id)
            ->where('product_id', $stock->product_id)
            ->sum('value_delta');

        expect(bccomp((string) $stock->total_value, bcadd((string) $ledger, '0', 4), 4))->toBe(0);
    }
});

it('sets opening costs through a ledger entry and does not overwrite unless forced', function () {
    $w = vlWorld();
    vlReceipt($w, $w->a, $w->p1, '10', '0');

    $file = tempnam(sys_get_temp_dir(), 'cost');
    file_put_contents($file, "sku,unit_cost\nSKU-1,150.5\nSKU-404,10\n");

    $this->artisan('stockpilot:set-costs', ['file' => $file, '--user' => $w->admin->email])->assertSuccessful();

    $movement = StockMovement::where('movement_type', MovementType::Revaluation->value)->first();

    expect(vlStock($w->a, $w->p1)->total_value)->toBe('1505.0000')
        ->and($movement->quantity_delta)->toBe('0.000')
        ->and($movement->value_delta)->toBe('1505.0000')
        ->and(AuditLog::where('action', 'stock.revalued')->count())->toBe(1);

    file_put_contents($file, "sku,unit_cost\nSKU-1,100\n");

    $this->artisan('stockpilot:set-costs', ['file' => $file, '--user' => $w->admin->email])->assertSuccessful();

    expect(vlStock($w->a, $w->p1)->total_value)->toBe('1505.0000')
        ->and(StockMovement::where('movement_type', MovementType::Revaluation->value)->count())->toBe(1);

    $this->artisan('stockpilot:set-costs', ['file' => $file, '--user' => $w->admin->email, '--force' => true])->assertSuccessful();

    $latest = StockMovement::where('movement_type', MovementType::Revaluation->value)->orderByDesc('id')->first();

    expect(vlStock($w->a, $w->p1)->total_value)->toBe('1000.0000')
        ->and($latest->value_delta)->toBe('-505.0000');

    unlink($file);
});

it('requires a unit cost on goods receipts and stores it', function () {
    $w = vlWorld();
    $payload = ['warehouse_id' => $w->a->id, 'items' => [['product_id' => $w->p1->id, 'quantity' => '10']]];

    $this->actingAs($w->staff)->post('/goods-receipts', $payload)->assertSessionHasErrors('items.0.unit_cost');

    foreach (['-1', 'abc', '1.23456'] as $bad) {
        $payload['items'][0]['unit_cost'] = $bad;
        $this->actingAs($w->staff)->post('/goods-receipts', $payload)->assertSessionHasErrors('items.0.unit_cost');
    }

    expect(GoodsReceipt::count())->toBe(0);

    $payload['items'][0]['unit_cost'] = '1500.5';
    $this->actingAs($w->staff)->post('/goods-receipts', $payload)->assertRedirect();

    $receipt = GoodsReceipt::first();

    expect($receipt->items()->first()->unit_cost)->toBe('1500.5000');

    $this->actingAs($w->staff)->post("/goods-receipts/{$receipt->id}/post")->assertSessionHas('success');

    expect(vlStock($w->a, $w->p1)->total_value)->toBe('15005.0000');
});

it('shows valuation only to admin and manager and reports totals and missing costs', function () {
    $w = vlWorld();
    vlReceipt($w, $w->a, $w->p1, '10', '100');  // GD-A: 1000
    vlReceipt($w, $w->b, $w->p1, '5', '50');    // GD-B: 250
    vlReceipt($w, $w->a, $w->p2, '4', '0');     // GD-A: tanpa biaya

    $this->actingAs($w->staff)->get('/valuation')->assertForbidden();

    $this->actingAs($w->manager)->get('/valuation')->assertInertia(fn (Assert $page) => $page
        ->component('valuation/index')
        ->where('totals.value', '1250.0000')
        ->where('totals.rows', 3)
        ->where('totals.missing_cost', 1)
        ->has('byWarehouse', 2)
        ->where('stocks.data.0.avg_cost', '100.0000'));

    $this->actingAs($w->manager)->get("/valuation?warehouse_id={$w->a->id}")->assertInertia(fn (Assert $page) => $page
        ->where('totals.value', '1000.0000')
        ->where('totals.rows', 2));

    auth()->logout();
    $this->get('/valuation')->assertRedirect('/login');
});

it('rejects impossible values at database level', function () {
    $w = vlWorld();
    $row = fn (array $extra) => DB::table('inventory_stocks')->insert([
        'warehouse_id' => $w->a->id,
        'product_id' => $w->p1->id,
        ...$extra,
    ]);

    expect(fn () => $row(['quantity' => 5, 'total_value' => -1]))->toThrow(QueryException::class)
        ->and(fn () => $row(['quantity' => 0, 'total_value' => 5]))->toThrow(QueryException::class);
})->skip(fn () => !in_array(DB::getDriverName(), ['mysql', 'mariadb']), 'CHECK constraint membutuhkan MySQL/MariaDB');
