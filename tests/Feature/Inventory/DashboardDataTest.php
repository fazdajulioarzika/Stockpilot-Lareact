<?php

use App\Enums\MovementType;
use App\Enums\Role;
use App\Enums\StocktakeStatus;
use App\Enums\TransferStatus;
use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function ddUser(Role $role, array $warehouseIds = []): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();
    $user->warehouses()->attach($warehouseIds);

    return $user->fresh();
}

function ddWorld(): object
{
    $category = Category::create(['name' => 'Cat', 'slug' => 'cat']);
    $unit = Unit::create(['name' => 'Pieces', 'symbol' => 'pcs']);

    $product = Product::create([
        'category_id' => $category->id, 'unit_id' => $unit->id,
        'sku' => 'SKU-1', 'name' => 'Produk 1', 'min_stock' => 5,
    ]);
    Product::create([
        'category_id' => $category->id, 'unit_id' => $unit->id,
        'sku' => 'SKU-X', 'name' => 'Nonaktif', 'is_active' => false,
    ]);

    $a = Warehouse::create(['code' => 'GD-A', 'name' => 'A']);
    $b = Warehouse::create(['code' => 'GD-B', 'name' => 'B']);
    $admin = ddUser(Role::Admin);

    $move = fn (Warehouse $w, MovementType $type, string $delta) => StockMovement::create([
        'product_id' => $product->id, 'warehouse_id' => $w->id, 'movement_type' => $type,
        'quantity_delta' => $delta, 'reference_type' => 'test', 'reference_id' => 1,
        'created_by' => $admin->id,
    ]);

    foreach ([[$a, '3'], [$b, '20']] as [$warehouse, $qty]) {
        InventoryStock::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => $qty]);
        $move($warehouse, MovementType::Receipt, $qty);
    }

    // Keluar yang dihitung (issue) dan yang tidak (transfer_out).
    $move($a, MovementType::Issue, '-2');
    $move($a, MovementType::TransferOut, '-1');

    return (object) [
        'a' => $a,
        'b' => $b,
        'admin' => $admin,
        'manager' => ddUser(Role::Manager),
        'staffA' => ddUser(Role::WarehouseStaff, [$a->id]),
        'staffB' => ddUser(Role::WarehouseStaff, [$b->id]),
        'nobody' => ddUser(Role::WarehouseStaff),
    ];
}

it('scopes the KPIs to the warehouses of the user', function () {
    $w = ddWorld();

    $this->actingAs($w->staffA)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->where('scope', 'GD-A')
        ->where('kpi.total_sku', 1) // produk nonaktif tidak dihitung
        ->where('kpi.total_quantity', '3.000')
        ->where('kpi.stock_rows', 1)
        ->where('kpi.low_stock', 1));

    $this->actingAs($w->staffB)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('kpi.total_quantity', '20.000')
        ->where('kpi.low_stock', 0)
        ->has('lowStock', 0));

    $this->actingAs($w->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('scope', 'Semua gudang')
        ->where('kpi.total_quantity', '23.000')
        ->where('kpi.stock_rows', 2)
        ->where('kpi.low_stock', 1)
        ->has('lowStock', 1)
        ->where('lowStock.0.warehouse.code', 'GD-A'));

    $this->actingAs($w->nobody)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('scope', 'Belum ditugaskan ke gudang')
        ->where('kpi.total_quantity', '0.000')
        ->has('recent', 0));
});

it('scopes the recent movements', function () {
    $w = ddWorld();

    $this->actingAs($w->staffA)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->has('recent', 3)); // receipt, issue, transfer_out di GD-A

    $this->actingAs($w->admin)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->has('recent', 4));
});

it('builds a 14 day chart from receipts and issues only', function () {
    $w = ddWorld();

    $this->actingAs($w->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->has('chart', 14)
        ->where('chart.0.masuk', '0.000')
        ->where('chart.13.masuk', '23.000')
        ->where('chart.13.keluar', '2.000')); // transfer_out tidak ikut

    $this->actingAs($w->staffB)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('chart.13.masuk', '20.000')
        ->where('chart.13.keluar', '0.000'));
});

it('lists the work waiting for each role', function () {
    $w = ddWorld();

    $pending = StockTransfer::create(['source_warehouse_id' => $w->a->id, 'destination_warehouse_id' => $w->b->id, 'created_by' => $w->staffA->id]);
    $pending->forceFill(['status' => TransferStatus::PendingApproval])->save();

    $approved = StockTransfer::create(['source_warehouse_id' => $w->a->id, 'destination_warehouse_id' => $w->b->id, 'created_by' => $w->staffA->id]);
    $approved->forceFill(['status' => TransferStatus::Approved])->save();

    $stocktake = Stocktake::create(['warehouse_id' => $w->a->id, 'created_by' => $w->staffA->id]);
    $stocktake->forceFill(['status' => StocktakeStatus::PendingApproval])->save();

    $has = fn (string $href, int $count) => fn ($attention) => collect($attention)
        ->contains(fn ($row) => $row['href'] === $href && $row['count'] === $count);

    $this->actingAs($w->manager)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('attention', $has('/transfers?status=pending_approval', 1))
        ->where('attention', $has('/stocktakes?status=pending_approval', 1))
        ->where('attention', fn ($a) => !$has('/transfers?status=approved', 1)($a)));

    $this->actingAs($w->staffA)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('attention', $has('/transfers?status=approved', 1))
        ->where('attention', fn ($a) => !$has('/transfers?status=pending_approval', 1)($a)));

    // Gudang tujuan tidak punya transfer siap kirim.
    $this->actingAs($w->staffB)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('attention', fn ($a) => !$has('/transfers?status=approved', 1)($a)));
});
