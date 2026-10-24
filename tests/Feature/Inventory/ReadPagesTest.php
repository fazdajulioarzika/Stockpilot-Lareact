<?php

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function readWorld(): object
{
    $category = Category::create(['name' => 'Cat', 'slug' => 'cat']);
    $unit = Unit::create(['name' => 'Pieces', 'symbol' => 'pcs']);
    $product = Product::create([
        'category_id' => $category->id,
        'unit_id' => $unit->id,
        'sku' => 'SKU-1',
        'name' => 'Produk 1',
        'min_stock' => 5,
    ]);
    $a = Warehouse::create(['code' => 'GD-A', 'name' => 'A']);
    $b = Warehouse::create(['code' => 'GD-B', 'name' => 'B']);

    $admin = User::factory()->create();
    $admin->forceFill(['role' => Role::Admin])->save();

    foreach ([[$a, '3'], [$b, '20']] as [$warehouse, $qty]) {
        InventoryStock::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => $qty,
        ]);
        StockMovement::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'movement_type' => MovementType::Receipt,
            'quantity_delta' => $qty,
            'reference_type' => 'App\Models\GoodsReceipt',
            'reference_id' => 1,
            'created_by' => $admin->id,
        ]);
    }

    $staff = User::factory()->create();
    $staff->forceFill(['role' => Role::WarehouseStaff])->save();
    $staff->warehouses()->attach($a->id);

    return (object) ['a' => $a, 'b' => $b, 'staff' => $staff->fresh(), 'admin' => $admin->fresh()];
}

it('shows staff only stock from assigned warehouses', function () {
    $w = readWorld();

    $this->actingAs($w->staff)->get('/inventory')->assertInertia(fn (Assert $page) => $page
        ->component('inventory/index')
        ->has('stocks.data', 1)
        ->where('stocks.data.0.warehouse.code', 'GD-A'));
});

it('shows admin stock from every warehouse', function () {
    $w = readWorld();

    $this->actingAs($w->admin)->get('/inventory')
        ->assertInertia(fn (Assert $page) => $page->has('stocks.data', 2));
});

it('does not leak other warehouses through the warehouse filter', function () {
    $w = readWorld();

    $this->actingAs($w->staff)->get("/inventory?warehouse_id={$w->b->id}")
        ->assertInertia(fn (Assert $page) => $page->has('stocks.data', 0));

    $this->actingAs($w->staff)->get("/stock-movements?warehouse_id={$w->b->id}")
        ->assertInertia(fn (Assert $page) => $page->has('movements.data', 0));
});

it('filters low stock rows', function () {
    $w = readWorld();

    $this->actingAs($w->admin)->get('/inventory?low=1')->assertInertia(fn (Assert $page) => $page
        ->has('stocks.data', 1)
        ->where('stocks.data.0.warehouse.code', 'GD-A'));
});

it('scopes the ledger to assigned warehouses and links to the source document', function () {
    $w = readWorld();

    $this->actingAs($w->staff)->get('/stock-movements')->assertInertia(fn (Assert $page) => $page
        ->component('stock-movements/index')
        ->has('movements.data', 1)
        ->where('movements.data.0.reference_url', '/goods-receipts/1'));
});

it('redirects guests away from inventory and ledger', function (string $url) {
    $this->get($url)->assertRedirect('/login');
})->with(['/inventory', '/stock-movements']);
