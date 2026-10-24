<?php

use App\Actions\Inventory\PostGoodsReceipt;
use App\Enums\DocumentStatus;
use App\Enums\Role;
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
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function httpUser(Role $role, array $warehouseIds = []): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();
    $user->warehouses()->attach($warehouseIds);

    return $user->fresh();
}

function httpWorld(): object
{
    $category = Category::create(['name' => 'Cat', 'slug' => 'cat']);
    $unit = Unit::create(['name' => 'Pieces', 'symbol' => 'pcs']);
    $make = fn (string $sku) => Product::create([
        'category_id' => $category->id,
        'unit_id' => $unit->id,
        'sku' => $sku,
        'name' => $sku,
    ]);

    return (object) [
        'a' => Warehouse::create(['code' => 'GD-A', 'name' => 'A']),
        'b' => Warehouse::create(['code' => 'GD-B', 'name' => 'B']),
        'p1' => $make('SKU-1'),
        'p2' => $make('SKU-2'),
    ];
}

function receiptPayload(object $w, ?array $items = null): array
{
    return [
        'warehouse_id' => $w->a->id,
        'reference' => 'PO-1',
        'items' => $items ?? [['product_id' => $w->p1->id, 'quantity' => '10']],
    ];
}

function httpDraftReceipt(object $w, User $user, ?Warehouse $warehouse = null): GoodsReceipt
{
    $receipt = GoodsReceipt::create([
        'warehouse_id' => ($warehouse ?? $w->a)->id,
        'created_by' => $user->id,
    ]);
    $receipt->items()->create(['product_id' => $w->p1->id, 'quantity' => '10']);

    return $receipt;
}

it('lets assigned staff create a draft without touching stock', function () {
    $w = httpWorld();
    $staff = httpUser(Role::WarehouseStaff, [$w->a->id]);

    $this->actingAs($staff)->post('/goods-receipts', receiptPayload($w))->assertRedirect();

    $receipt = GoodsReceipt::first();

    expect($receipt->status)->toBe(DocumentStatus::Draft)
        ->and($receipt->number)->toStartWith('GR-')
        ->and($receipt->items)->toHaveCount(1)
        ->and(InventoryStock::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0);
});

it('rejects creating a document in an unassigned warehouse', function () {
    $w = httpWorld();
    $staff = httpUser(Role::WarehouseStaff, [$w->b->id]);

    $this->actingAs($staff)->post('/goods-receipts', receiptPayload($w))->assertForbidden();

    expect(GoodsReceipt::count())->toBe(0);
});

it('lets a manager view but not create or post', function () {
    $w = httpWorld();
    $admin = httpUser(Role::Admin);
    $receipt = httpDraftReceipt($w, $admin);
    $manager = httpUser(Role::Manager);

    $this->actingAs($manager)->get('/goods-receipts')->assertOk();
    $this->actingAs($manager)->get("/goods-receipts/{$receipt->id}")->assertOk();
    $this->actingAs($manager)->get('/goods-receipts/create')->assertForbidden();
    $this->actingAs($manager)->post('/goods-receipts', receiptPayload($w))->assertForbidden();
    $this->actingAs($manager)->post("/goods-receipts/{$receipt->id}/post")->assertForbidden();

    expect(StockMovement::count())->toBe(0);
});

it('rejects invalid item lines', function () {
    $w = httpWorld();
    $w->p2->update(['is_active' => false]);
    $staff = httpUser(Role::WarehouseStaff, [$w->a->id]);

    $cases = [
        ['items.0.quantity', [['product_id' => $w->p1->id, 'quantity' => '0']]],
        ['items.0.quantity', [['product_id' => $w->p1->id, 'quantity' => '-5']]],
        ['items.0.quantity', [['product_id' => $w->p1->id, 'quantity' => '1.2345']]],
        ['items.1.product_id', [
            ['product_id' => $w->p1->id, 'quantity' => '1'],
            ['product_id' => $w->p1->id, 'quantity' => '2'],
        ]],
        ['items.0.product_id', [['product_id' => $w->p2->id, 'quantity' => '1']]], // produk nonaktif
        ['items', []],
    ];

    foreach ($cases as [$errorKey, $items]) {
        $this->actingAs($staff)
            ->post('/goods-receipts', receiptPayload($w, $items))
            ->assertSessionHasErrors($errorKey);
    }

    expect(GoodsReceipt::count())->toBe(0);
});

it('requires a reason for stock issues', function () {
    $w = httpWorld();
    $staff = httpUser(Role::WarehouseStaff, [$w->a->id]);

    $this->actingAs($staff)
        ->post('/stock-issues', [
            'warehouse_id' => $w->a->id,
            'items' => [['product_id' => $w->p1->id, 'quantity' => '1']],
        ])
        ->assertSessionHasErrors('reason');

    expect(StockIssue::count())->toBe(0);
});

it('posts a draft over HTTP exactly once', function () {
    $w = httpWorld();
    $staff = httpUser(Role::WarehouseStaff, [$w->a->id]);
    $receipt = httpDraftReceipt($w, $staff);

    $this->actingAs($staff)->post("/goods-receipts/{$receipt->id}/post")->assertRedirect();
    $this->actingAs($staff)->post("/goods-receipts/{$receipt->id}/post")->assertForbidden();

    expect(StockMovement::count())->toBe(1)
        ->and((float) InventoryStock::first()->quantity)->toBe(10.0)
        ->and($receipt->fresh()->status)->toBe(DocumentStatus::Posted);
});

it('keeps an issue as draft and reports an error when stock is insufficient', function () {
    $w = httpWorld();
    $staff = httpUser(Role::WarehouseStaff, [$w->a->id]);

    $issue = StockIssue::create([
        'warehouse_id' => $w->a->id,
        'reason' => 'Penjualan',
        'created_by' => $staff->id,
    ]);
    $issue->items()->create(['product_id' => $w->p1->id, 'quantity' => '5']);

    $this->actingAs($staff)
        ->post("/stock-issues/{$issue->id}/post")
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($issue->fresh()->status)->toBe(DocumentStatus::Draft)
        ->and(StockMovement::count())->toBe(0)
        ->and(InventoryStock::count())->toBe(0);
});

it('hides and blocks documents from unassigned warehouses', function () {
    $w = httpWorld();
    $admin = httpUser(Role::Admin);
    $mine = httpDraftReceipt($w, $admin, $w->a);
    $other = httpDraftReceipt($w, $admin, $w->b);
    $staff = httpUser(Role::WarehouseStaff, [$w->a->id]);

    $this->actingAs($staff)->get("/goods-receipts/{$other->id}")->assertForbidden();
    $this->actingAs($staff)->get("/goods-receipts/{$other->id}/edit")->assertForbidden();
    $this->actingAs($staff)->put("/goods-receipts/{$other->id}", receiptPayload($w))->assertForbidden();
    $this->actingAs($staff)->post("/goods-receipts/{$other->id}/post")->assertForbidden();
    $this->actingAs($staff)->delete("/goods-receipts/{$other->id}")->assertForbidden();

    $this->actingAs($staff)->get('/goods-receipts')->assertInertia(fn (Assert $page) => $page
        ->component('goods-receipts/index')
        ->has('documents.data', 1)
        ->where('documents.data.0.id', $mine->id));

    expect(StockMovement::count())->toBe(0)
        ->and(GoodsReceipt::count())->toBe(2);
});

it('replaces items when updating a draft and refuses to update a posted document', function () {
    $w = httpWorld();
    $staff = httpUser(Role::WarehouseStaff, [$w->a->id]);
    $receipt = httpDraftReceipt($w, $staff);

    $payload = receiptPayload($w, [['product_id' => $w->p2->id, 'quantity' => '4']]);

    $this->actingAs($staff)->put("/goods-receipts/{$receipt->id}", $payload)->assertRedirect();

    expect($receipt->fresh()->items->pluck('product_id')->all())->toBe([$w->p2->id]);

    app(PostGoodsReceipt::class)->execute($receipt->fresh(), $staff);

    $this->actingAs($staff)->put("/goods-receipts/{$receipt->id}", $payload)->assertForbidden();
    $this->actingAs($staff)->delete("/goods-receipts/{$receipt->id}")->assertForbidden();

    expect(GoodsReceipt::count())->toBe(1)
        ->and(StockMovement::count())->toBe(1);
});

it('deletes a draft together with its items', function () {
    $w = httpWorld();
    $staff = httpUser(Role::WarehouseStaff, [$w->a->id]);
    $receipt = httpDraftReceipt($w, $staff);

    $this->actingAs($staff)->delete("/goods-receipts/{$receipt->id}")->assertRedirect();

    expect(GoodsReceipt::count())->toBe(0)
        ->and(\App\Models\GoodsReceiptItem::count())->toBe(0);
});
