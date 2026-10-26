<?php

use App\Actions\Inventory\PostGoodsReceipt;
use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\GoodsReceipt;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\StockIssue;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function rpUser(Role $role, array $warehouseIds = []): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();
    $user->warehouses()->attach($warehouseIds);

    return $user->fresh();
}

function rpWorld(): object
{
    $category = Category::create(['name' => 'Cat', 'slug' => 'cat']);
    $unit = Unit::create(['name' => 'Pieces', 'symbol' => 'pcs']);
    $make = fn (string $sku, ?string $name = null) => Product::create([
        'category_id' => $category->id,
        'unit_id' => $unit->id,
        'sku' => $sku,
        'name' => $name ?? $sku,
    ]);

    $a = Warehouse::create(['code' => 'GD-A', 'name' => 'A']);
    $b = Warehouse::create(['code' => 'GD-B', 'name' => 'B']);

    return (object) [
        'a' => $a,
        'b' => $b,
        'p1' => $make('SKU-1'),
        'p2' => $make('SKU-2'),
        'p3' => $make('SKU-3', '=SUM(1+1)'),
        'admin' => rpUser(Role::Admin),
        'manager' => rpUser(Role::Manager),
        'staffA' => rpUser(Role::WarehouseStaff, [$a->id]),
        'staffB' => rpUser(Role::WarehouseStaff, [$b->id]),
    ];
}

function rpDay(int $offset): Carbon
{
    return Carbon::today()->addDays($offset)->setTime(10, 0);
}

function rpMove(object $w, Warehouse $warehouse, Product $product, MovementType $type, string $qty, string $value, Carbon $at, string $ref = 'test'): StockMovement
{
    return StockMovement::create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'movement_type' => $type,
        'quantity_delta' => $qty,
        'value_delta' => $value,
        'reference_type' => $ref,
        'reference_id' => 1,
        'created_by' => $w->admin->id,
        'created_at' => $at,
    ]);
}
function rpStock(Warehouse $warehouse, Product $product, string $qty, string $value = '0'): InventoryStock
{
    $stock = new InventoryStock();
    // forceFill: total_value memang tidak fillable, hanya StockService yang boleh mengubahnya.
    $stock->forceFill([
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'quantity' => $qty,
        'total_value' => $value,
    ])->save();

    return $stock;
}

it('computes opening, inflow, outflow and closing for the period from the ledger', function () {
    $w = rpWorld();
    rpMove($w, $w->a, $w->p1, MovementType::Receipt, '10', '1000', rpDay(-40));
    rpMove($w, $w->a, $w->p1, MovementType::Receipt, '5', '600', rpDay(-10));
    rpMove($w, $w->a, $w->p1, MovementType::Issue, '-3', '-330', rpDay(-5));
    rpMove($w, $w->a, $w->p1, MovementType::Receipt, '100', '9999', rpDay(1)); // setelah periode
    rpMove($w, $w->a, $w->p2, MovementType::Receipt, '4', '40', rpDay(-40));   // hanya saldo awal

    $this->actingAs($w->manager)->get('/reports')->assertInertia(fn (Assert $page) => $page
        ->component('reports/index')
        ->has('rows.data', 2)
        ->where('rows.data.0.product.sku', 'SKU-1')
        ->where('rows.data.0.opening', '10.000')
        ->where('rows.data.0.inflow', '5.000')
        ->where('rows.data.0.outflow', '3.000')
        ->where('rows.data.0.closing', '12.000')
        ->where('rows.data.0.opening_value', '1000.0000')
        ->where('rows.data.0.closing_value', '1270.0000')
        ->where('rows.data.1.product.sku', 'SKU-2')
        ->where('rows.data.1.closing', '4.000')
        ->where('totals.rows', 2)
        ->where('totals.inflow', '5.000')
        ->where('totals.outflow', '3.000')
        ->where('totals.opening_value', '1040.0000')
        ->where('totals.closing_value', '1310.0000')
        ->where('error', null));
});

it('hides values from staff and scopes rows to assigned warehouses', function () {
    $w = rpWorld();
    rpMove($w, $w->a, $w->p1, MovementType::Receipt, '10', '1000', rpDay(-2));
    rpMove($w, $w->b, $w->p1, MovementType::Receipt, '7', '700', rpDay(-2));

    $this->actingAs($w->staffA)->get('/reports')->assertInertia(fn (Assert $page) => $page
        ->has('rows.data', 1)
        ->where('rows.data.0.warehouse.code', 'GD-A')
        ->where('canValue', false)
        ->missing('rows.data.0.opening_value')
        ->missing('totals.opening_value'));

    // Filter gudang tidak membuka gudang lain.
    $this->actingAs($w->staffA)->get("/reports?warehouse_id={$w->b->id}")
        ->assertInertia(fn (Assert $page) => $page->has('rows.data', 0));

    $this->actingAs($w->admin)->get('/reports')
        ->assertInertia(fn (Assert $page) => $page->has('rows.data', 2));

    $this->actingAs($w->admin)->get('/reports?search=SKU-1')
        ->assertInertia(fn (Assert $page) => $page->has('rows.data', 2));

    $this->actingAs($w->admin)->get('/reports?search=TIDAK-ADA')
        ->assertInertia(fn (Assert $page) => $page->has('rows.data', 0));
});

it('builds the daily trend from receipts and issues net of reversals', function () {
    $w = rpWorld();
    $today = rpDay(0);

    rpMove($w, $w->a, $w->p1, MovementType::Receipt, '10', '1000', $today);
    rpMove($w, $w->a, $w->p1, MovementType::TransferOut, '-4', '-400', $today);          // tidak dihitung
    rpMove($w, $w->a, $w->p1, MovementType::Issue, '-2', '-200', $today);
    rpMove($w, $w->a, $w->p1, MovementType::Reversal, '-3', '-300', $today, GoodsReceipt::class); // mengurangi masuk
    rpMove($w, $w->a, $w->p1, MovementType::Reversal, '1', '100', $today, StockIssue::class);     // mengurangi keluar

    $this->actingAs($w->manager)->get('/reports')->assertInertia(fn (Assert $page) => $page
        ->where('trend.granularity', 'day')
        ->has('trend.points', 30)
        ->where('trend.points.29.masuk', '7.000')
        ->where('trend.points.29.keluar', '1.000')
        ->where('trend.points.28.masuk', '0.000'));
});

it('switches to a monthly trend for long periods', function () {
    $w = rpWorld();
    $from = Carbon::today()->subDays(100)->toDateString();
    $to = Carbon::today()->toDateString();

    $this->actingAs($w->manager)->get("/reports?from={$from}&to={$to}")->assertInertia(fn (Assert $page) => $page
        ->where('trend.granularity', 'month')
        ->where('error', null)
        ->where('filters.from', $from));
});

it('falls back safely for invalid, reversed or oversized periods', function () {
    $w = rpWorld();
    $today = Carbon::today()->toDateString();

    foreach (['from=abc', 'from=2026-02-30', 'from=2026-10-10&to=2026-10-01'] as $query) {
        $this->actingAs($w->manager)->get("/reports?{$query}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('error', fn ($e) => $e !== null)
            ->where('filters.to', $today));
    }

    $this->actingAs($w->manager)->get('/reports?from=2020-01-01&to=2026-01-01')->assertInertia(fn (Assert $page) => $page
        ->where('error', fn ($e) => $e !== null)
        ->where('filters.to', '2026-01-01')
        ->where('filters.from', '2025-01-01'));
});

it('exports valuation in Indonesian Excel format and in international format', function () {
    $w = rpWorld();
    rpStock($w->a, $w->p1, '10', '1000.5');

    $response = $this->actingAs($w->manager)->get('/exports/valuation');
    $response->assertOk();
    $content = $response->streamedContent();

    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and(str_starts_with($content, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($content)->toContain('SKU-1')
        ->toContain(';')
        ->toContain('1000,5')
        ->toContain('100,05');

    $intl = $this->actingAs($w->manager)->get('/exports/valuation?format=intl')->streamedContent();

    expect($intl)->toContain('SKU-1,')
        ->toContain('1000.5')
        ->toContain('100.05')
        ->not->toContain(';');
});

it('keeps valuation exports and values away from staff', function () {
    $w = rpWorld();

    $this->actingAs($w->staffA)->get('/exports/valuation')->assertForbidden();
    $this->actingAs($w->staffA)->get('/exports/inventory')->assertOk();
});

it('scopes inventory exports to assigned warehouses and neutralises formula injection', function () {
    $w = rpWorld();
    rpStock($w->a, $w->p1, '5');
    rpStock($w->a, $w->p3, '2');
    rpStock($w->b, $w->p2, '7');

    $content = $this->actingAs($w->staffA)->get('/exports/inventory')->streamedContent();

    expect($content)->toContain('SKU-1')
        ->toContain("'=SUM(1+1)")
        ->not->toContain('SKU-2');

    $admin = $this->actingAs($w->admin)->get('/exports/inventory')->streamedContent();

    expect($admin)->toContain('SKU-2');
});

it('exports the ledger with document numbers, scope, value permission and an audit entry', function () {
    $w = rpWorld();

    $receipt = GoodsReceipt::create(['warehouse_id' => $w->a->id, 'created_by' => $w->admin->id]);
    $receipt->items()->create(['product_id' => $w->p1->id, 'quantity' => '10', 'unit_cost' => '100']);
    app(PostGoodsReceipt::class)->execute($receipt, $w->admin);

    $number = $receipt->fresh()->number;
    $lines = fn (string $content) => count(array_filter(explode("\n", $content)));

    $manager = $this->actingAs($w->manager)->get('/exports/stock-movements?format=intl')->streamedContent();

    expect($manager)->toContain($number)->toContain('Nilai')->toContain('Barang masuk')
        ->and($lines($manager))->toBe(2);

    $staff = $this->actingAs($w->staffA)->get('/exports/stock-movements?format=intl')->streamedContent();

    expect($staff)->toContain($number)->not->toContain('Nilai');

    $other = $this->actingAs($w->staffB)->get('/exports/stock-movements?format=intl')->streamedContent();

    expect($lines($other))->toBe(1); // hanya header

    $filtered = $this->actingAs($w->manager)->get('/exports/stock-movements?type=issue')->streamedContent();

    expect($lines($filtered))->toBe(1);

    $log = AuditLog::where('action', 'report.exported')->where('user_id', $w->manager->id)->first();

    expect($log)->not->toBeNull()->and($log->metadata['report'])->toBe('stock-movements');
});

it('exports the period report with the same numbers as the page', function () {
    $w = rpWorld();
    rpMove($w, $w->a, $w->p1, MovementType::Receipt, '10', '1000', rpDay(-40));
    rpMove($w, $w->a, $w->p1, MovementType::Receipt, '5', '600', rpDay(-10));
    rpMove($w, $w->a, $w->p1, MovementType::Issue, '-3', '-330', rpDay(-5));

    $manager = $this->actingAs($w->manager)->get('/reports/export?format=intl')->streamedContent();

    expect($manager)->toContain('Nilai awal')->toContain('GD-A,SKU-1,SKU-1,pcs,10,5,3,12,1000,1270');

    $staff = $this->actingAs($w->staffA)->get('/reports/export?format=intl')->streamedContent();

    expect($staff)->toContain('GD-A,SKU-1,SKU-1,pcs,10,5,3,12')
        ->not->toContain('Nilai awal')
        ->not->toContain('1270');
});

it('redirects guests away from reports and exports', function (string $url) {
    $this->get($url)->assertRedirect('/login');
})->with(['/reports', '/reports/export', '/exports/inventory', '/exports/stock-movements', '/exports/valuation']);
