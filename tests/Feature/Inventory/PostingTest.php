<?php

use App\Actions\Inventory\PostGoodsReceipt;
use App\Actions\Inventory\PostStockIssue;
use App\Enums\DocumentStatus;
use App\Enums\MovementType;
use App\Enums\Role;
use App\Exceptions\DocumentAlreadyPostedException;
use App\Exceptions\InsufficientStockException;
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

uses(RefreshDatabase::class);

function inv(): object
{
    $category = Category::create(['name' => 'Cat', 'slug' => 'cat']);
    $unit = Unit::create(['name' => 'Pieces', 'symbol' => 'pcs']);
    $product = fn (string $sku) => Product::create([
        'category_id' => $category->id,
        'unit_id' => $unit->id,
        'sku' => $sku,
        'name' => $sku,
    ]);

    $user = User::factory()->create();
    $user->forceFill(['role' => Role::Admin])->save();

    return (object) [
        'warehouse' => Warehouse::create(['code' => 'GD-A', 'name' => 'A']),
        'p1' => $product('SKU-1'),
        'p2' => $product('SKU-2'),
        'user' => $user->fresh(),
    ];
}

function draftReceipt(object $inv, array $lines): GoodsReceipt
{
    $receipt = GoodsReceipt::create([
        'warehouse_id' => $inv->warehouse->id,
        'created_by' => $inv->user->id,
    ]);

    foreach ($lines as [$product, $qty]) {
        $receipt->items()->create(['product_id' => $product->id, 'quantity' => $qty]);
    }

    return $receipt;
}

function draftIssue(object $inv, array $lines): StockIssue
{
    $issue = StockIssue::create([
        'warehouse_id' => $inv->warehouse->id,
        'reason' => 'Penjualan',
        'created_by' => $inv->user->id,
    ]);

    foreach ($lines as [$product, $qty]) {
        $issue->items()->create(['product_id' => $product->id, 'quantity' => $qty]);
    }

    return $issue;
}

function balance(object $inv, Product $product): float
{
    return (float) InventoryStock::where('warehouse_id', $inv->warehouse->id)
        ->where('product_id', $product->id)
        ->value('quantity');
}

it('adds stock and writes one movement per line when a receipt is posted', function () {
    $i = inv();
    $receipt = draftReceipt($i, [[$i->p1, '10'], [$i->p2, '4.5']]);

    app(PostGoodsReceipt::class)->execute($receipt, $i->user);

    expect(balance($i, $i->p1))->toBe(10.0)
        ->and(balance($i, $i->p2))->toBe(4.5)
        ->and(StockMovement::count())->toBe(2)
        ->and($receipt->fresh()->status)->toBe(DocumentStatus::Posted)
        ->and($receipt->fresh()->posted_by)->toBe($i->user->id);
});

it('does not change balances while a document is still draft', function () {
    $i = inv();
    draftReceipt($i, [[$i->p1, '10']]);

    expect(InventoryStock::count())->toBe(0)->and(StockMovement::count())->toBe(0);
});

it('does not duplicate movements when posting twice', function () {
    $i = inv();
    $receipt = draftReceipt($i, [[$i->p1, '10']]);
    $action = app(PostGoodsReceipt::class);

    $action->execute($receipt, $i->user);

    expect(fn () => $action->execute($receipt, $i->user))
        ->toThrow(DocumentAlreadyPostedException::class);

    expect(StockMovement::count())->toBe(1)->and(balance($i, $i->p1))->toBe(10.0);
});

it('rejects posting a receipt with an inactive product', function () {
    $i = inv();
    $i->p1->update(['is_active' => false]);
    $receipt = draftReceipt($i, [[$i->p1, '10']]);

    expect(fn () => app(PostGoodsReceipt::class)->execute($receipt, $i->user))
        ->toThrow(DomainException::class);

    expect(StockMovement::count())->toBe(0)
        ->and($receipt->fresh()->status)->toBe(DocumentStatus::Draft);
});

it('reduces stock when an issue is posted with enough stock', function () {
    $i = inv();
    app(PostGoodsReceipt::class)->execute(draftReceipt($i, [[$i->p1, '10']]), $i->user);

    $issue = draftIssue($i, [[$i->p1, '3']]);
    app(PostStockIssue::class)->execute($issue, $i->user);

    expect(balance($i, $i->p1))->toBe(7.0)
        ->and(StockMovement::where('movement_type', MovementType::Issue)->first()->quantity_delta)->toBe('-3.000');
});

it('rejects an issue with insufficient stock and rolls back every line', function () {
    $i = inv();
    app(PostGoodsReceipt::class)->execute(
        draftReceipt($i, [[$i->p1, '10'], [$i->p2, '1']]),
        $i->user,
    );

    // Baris pertama (SKU-1) cukup, baris kedua (SKU-2) kurang.
    $issue = draftIssue($i, [[$i->p1, '5'], [$i->p2, '3']]);

    expect(fn () => app(PostStockIssue::class)->execute($issue, $i->user))
        ->toThrow(InsufficientStockException::class);

    expect(balance($i, $i->p1))->toBe(10.0)
        ->and(balance($i, $i->p2))->toBe(1.0)
        ->and(StockMovement::where('movement_type', MovementType::Issue)->count())->toBe(0)
        ->and($issue->fresh()->status)->toBe(DocumentStatus::Draft);
});

it('keeps balances reconcilable with the ledger', function () {
    $i = inv();
    app(PostGoodsReceipt::class)->execute(draftReceipt($i, [[$i->p1, '10'], [$i->p2, '8']]), $i->user);
    app(PostStockIssue::class)->execute(draftIssue($i, [[$i->p1, '2.5']]), $i->user);
    app(PostGoodsReceipt::class)->execute(draftReceipt($i, [[$i->p1, '1']]), $i->user);

    foreach ([$i->p1, $i->p2] as $product) {
        $ledger = (float) StockMovement::where('product_id', $product->id)
            ->where('warehouse_id', $i->warehouse->id)
            ->sum('quantity_delta');

        expect(balance($i, $product))->toBe($ledger);
    }
});

it('locks a posted document against edits and deletion', function () {
    $i = inv();
    $receipt = draftReceipt($i, [[$i->p1, '10']]);
    app(PostGoodsReceipt::class)->execute($receipt, $i->user);

    $posted = $receipt->fresh();

    expect(fn () => $posted->update(['notes' => 'ubah']))->toThrow(LogicException::class)
        ->and(fn () => $posted->delete())->toThrow(LogicException::class)
        ->and(fn () => $posted->items()->first()->update(['quantity' => 99]))->toThrow(LogicException::class);
});

it('requires an assigned warehouse and a writer role to post', function () {
    $i = inv();
    $receipt = draftReceipt($i, [[$i->p1, '10']]);

    $staff = User::factory()->create();
    $staff->forceFill(['role' => Role::WarehouseStaff])->save();
    $staff = $staff->fresh();

    expect($staff->can('post', $receipt))->toBeFalse(); // belum ditugaskan

    $staff->warehouses()->attach($i->warehouse->id);
    expect($staff->fresh()->can('post', $receipt))->toBeTrue();

    $manager = User::factory()->create();
    $manager->forceFill(['role' => Role::Manager])->save();
    expect($manager->fresh()->can('post', $receipt))->toBeFalse(); // manager hanya melihat
});
