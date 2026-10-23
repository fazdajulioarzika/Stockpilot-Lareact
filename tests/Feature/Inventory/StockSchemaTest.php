<?php

use App\Enums\MovementType;
use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class); // hapus jika sudah ada di tests/Pest.php

function makeProductAndWarehouse(): array
{
    $product = Product::create([
        'category_id' => Category::create(['name' => 'Cat', 'slug' => 'cat'])->id,
        'unit_id' => Unit::create(['name' => 'Pieces', 'symbol' => 'pcs'])->id,
        'sku' => 'SKU-1',
        'name' => 'Produk 1',
    ]);
    $warehouse = Warehouse::create(['code' => 'GD-A', 'name' => 'A']);

    return [$product, $warehouse];
}

$mysqlOnly = fn () => !in_array(DB::getDriverName(), ['mysql', 'mariadb']);

it('keeps one balance row per product and warehouse', function () {
    [$product, $warehouse] = makeProductAndWarehouse();
    $row = ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 5];

    InventoryStock::create($row);

    expect(fn () => InventoryStock::create($row))->toThrow(QueryException::class);
});

it('rejects negative balances at database level', function () {
    [$product, $warehouse] = makeProductAndWarehouse();

    expect(fn () => InventoryStock::create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => -1,
    ]))->toThrow(QueryException::class);
})->skip($mysqlOnly, 'CHECK constraint membutuhkan MySQL/MariaDB');

it('makes ledger entries immutable', function () {
    [$product, $warehouse] = makeProductAndWarehouse();
    $user = User::factory()->create();

    $movement = StockMovement::create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'movement_type' => MovementType::Receipt,
        'quantity_delta' => 10,
        'reference_type' => 'test',
        'reference_id' => 1,
        'created_by' => $user->id,
    ]);

    expect(fn () => $movement->update(['quantity_delta' => 99]))->toThrow(LogicException::class)
        ->and(fn () => $movement->delete())->toThrow(LogicException::class);
});
