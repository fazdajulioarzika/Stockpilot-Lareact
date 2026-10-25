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
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function shUser(Role $role, array $warehouseIds = []): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();
    $user->warehouses()->attach($warehouseIds);

    return $user->fresh();
}

function shWorld(): object
{
    $category = Category::create(['name' => 'Cat', 'slug' => 'cat']);
    $unit = Unit::create(['name' => 'Pieces', 'symbol' => 'pcs']);
    $a = Warehouse::create(['code' => 'GD-A', 'name' => 'A']);
    $b = Warehouse::create(['code' => 'GD-B', 'name' => 'B']);

    return (object) [
        'a' => $a,
        'b' => $b,
        'p1' => Product::create(['category_id' => $category->id, 'unit_id' => $unit->id, 'sku' => 'SKU-1', 'name' => 'SKU-1']),
        'p2' => Product::create(['category_id' => $category->id, 'unit_id' => $unit->id, 'sku' => 'SKU-2', 'name' => 'SKU-2']),
        'admin' => shUser(Role::Admin),
        'manager' => shUser(Role::Manager),
        'staffA' => shUser(Role::WarehouseStaff, [$a->id]),
        'staffB' => shUser(Role::WarehouseStaff, [$b->id]),
        'outsider' => shUser(Role::WarehouseStaff),
    ];
}

function shReceipt(object $w, Warehouse $warehouse, Product $product, string $qty): GoodsReceipt
{
    $receipt = GoodsReceipt::create(['warehouse_id' => $warehouse->id, 'created_by' => $w->admin->id]);
    $receipt->items()->create(['product_id' => $product->id, 'quantity' => $qty]);

    return $receipt;
}

function shSeed(object $w, Warehouse $warehouse, Product $product, string $qty): void
{
    app(PostGoodsReceipt::class)->execute(shReceipt($w, $warehouse, $product, $qty), $w->admin);
}

function shDraft(object $w): Stocktake
{
    return Stocktake::create(['warehouse_id' => $w->a->id, 'created_by' => $w->staffA->id]);
}

function shCounting(object $w): Stocktake
{
    $stocktake = shDraft($w);
    test()->actingAs($w->staffA)->post("/stocktakes/{$stocktake->id}/start")->assertRedirect();

    return $stocktake->fresh();
}

function shLines(Stocktake $stocktake, array $counts): array
{
    $items = $stocktake->items()->get()->keyBy('product_id');
    $lines = [];

    foreach ($counts as $productId => [$qty, $reason]) {
        $lines[] = ['item_id' => $items[$productId]->id, 'counted_qty' => $qty, 'reason' => $reason];
    }

    return ['lines' => $lines];
}

function shBalance(Warehouse $warehouse, Product $product): float
{
    return (float) InventoryStock::where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->value('quantity');
}

it('lets assigned staff create a draft without locking the warehouse or moving stock', function () {
    $w = shWorld();
    shSeed($w, $w->a, $w->p1, '10');

    $this->actingAs($w->staffA)
        ->post('/stocktakes', ['warehouse_id' => $w->a->id, 'notes' => 'Bulanan', 'product_ids' => [$w->p2->id]])
        ->assertRedirect();

    $stocktake = Stocktake::first();

    expect($stocktake->status)->toBe(StocktakeStatus::Draft)
        ->and($stocktake->number)->toStartWith('SO-')
        ->and($stocktake->items)->toHaveCount(1)
        ->and(shBalance($w->a, $w->p1))->toBe(10.0)
        ->and(StockMovement::count())->toBe(1);

    // Draft belum mengunci: penerimaan baru masih boleh diposting.
    $receipt = shReceipt($w, $w->a, $w->p1, '5');
    $this->actingAs($w->staffA)->post("/goods-receipts/{$receipt->id}/post")->assertSessionHas('success');

    expect(shBalance($w->a, $w->p1))->toBe(15.0);
});

it('rejects unassigned warehouses and non-writer roles when creating', function () {
    $w = shWorld();
    $payload = ['warehouse_id' => $w->a->id];

    $this->actingAs($w->staffB)->post('/stocktakes', $payload)->assertForbidden();
    $this->actingAs($w->manager)->post('/stocktakes', $payload)->assertForbidden();
    $this->actingAs($w->outsider)->post('/stocktakes', $payload)->assertForbidden();

    expect(Stocktake::count())->toBe(0);
});

it('renders the create and edit forms', function () {
    $w = shWorld();
    $stocktake = shDraft($w);
    $stocktake->items()->create(['product_id' => $w->p2->id]);

    $this->actingAs($w->staffA)->get('/stocktakes/create')->assertInertia(fn (Assert $page) => $page
        ->component('stocktakes/form')
        ->where('document', null)
        ->has('warehouses', 1)
        ->has('products', 2));

    $this->actingAs($w->staffA)->get("/stocktakes/{$stocktake->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->component('stocktakes/form')
        ->where('document.id', $stocktake->id)
        ->where('document.product_ids', [$w->p2->id]));

    $this->actingAs($w->manager)->get('/stocktakes/create')->assertForbidden();
    $this->actingAs($w->staffB)->get("/stocktakes/{$stocktake->id}/edit")->assertForbidden();
});

it('runs the full stocktake flow over HTTP and applies the adjustments', function () {
    $w = shWorld();
    shSeed($w, $w->a, $w->p1, '10');
    shSeed($w, $w->a, $w->p2, '4');

    $stocktake = shCounting($w);
    $id = $stocktake->id;

    expect($stocktake->status)->toBe(StocktakeStatus::Counting)
        ->and($stocktake->items)->toHaveCount(2);

    $this->actingAs($w->staffA)
        ->post("/stocktakes/{$id}/count", shLines($stocktake, [
            $w->p1->id => ['8', 'rusak'],
            $w->p2->id => ['4', null],
        ]))
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->actingAs($w->staffA)->post("/stocktakes/{$id}/submit")->assertSessionHas('success');
    expect($stocktake->fresh()->status)->toBe(StocktakeStatus::PendingApproval);

    $this->actingAs($w->manager)->post("/stocktakes/{$id}/approve")->assertSessionHas('success');

    expect($stocktake->fresh()->status)->toBe(StocktakeStatus::Posted)
        ->and(shBalance($w->a, $w->p1))->toBe(8.0)
        ->and(shBalance($w->a, $w->p2))->toBe(4.0)
        ->and(StockMovement::where('movement_type', MovementType::Adjustment)->count())->toBe(1);

    // Gudang terbuka lagi.
    $receipt = shReceipt($w, $w->a, $w->p1, '2');
    $this->actingAs($w->staffA)->post("/goods-receipts/{$receipt->id}/post")->assertSessionHas('success');

    expect(shBalance($w->a, $w->p1))->toBe(10.0);
});

it('validates count input and refuses to submit incomplete counts', function () {
    $w = shWorld();
    shSeed($w, $w->a, $w->p1, '10');
    $stocktake = shCounting($w);
    $id = $stocktake->id;
    $post = fn (array $body) => $this->actingAs($w->staffA)->post("/stocktakes/{$id}/count", $body);
    $item = $stocktake->items()->first();

    $post(shLines($stocktake, [$w->p1->id => ['-1', null]]))->assertSessionHasErrors('lines.0.counted_qty');
    $post(shLines($stocktake, [$w->p1->id => ['1.2345', null]]))->assertSessionHasErrors('lines.0.counted_qty');
    $post(shLines($stocktake, [$w->p1->id => ['abc', null]]))->assertSessionHasErrors('lines.0.counted_qty');
    $post(['lines' => []])->assertSessionHasErrors('lines');

    // Item yang bukan bagian opname ini: lolos format, ditolak action.
    $post(['lines' => [['item_id' => 999999, 'counted_qty' => '1']]])->assertSessionHas('error');

    expect($item->fresh()->counted_qty)->toBeNull();

    // Belum dihitung: tidak boleh diajukan.
    $this->actingAs($w->staffA)->post("/stocktakes/{$id}/submit")->assertSessionHas('error');
    expect($stocktake->fresh()->status)->toBe(StocktakeStatus::Counting);

    // Selisih tanpa alasan: tidak boleh diajukan.
    $post(shLines($stocktake, [$w->p1->id => ['7', null]]))->assertSessionHas('success');
    $this->actingAs($w->staffA)->post("/stocktakes/{$id}/submit")->assertSessionHas('error');
    expect($stocktake->fresh()->status)->toBe(StocktakeStatus::Counting);
});

it('keeps staff of other warehouses out of every stocktake action', function () {
    $w = shWorld();
    shSeed($w, $w->a, $w->p1, '10');
    $stocktake = shCounting($w);
    $id = $stocktake->id;
    $lines = shLines($stocktake, [$w->p1->id => ['8', 'rusak']]);

    foreach ([$w->staffB, $w->outsider] as $user) {
        $this->actingAs($user)->get("/stocktakes/{$id}")->assertForbidden();
        $this->actingAs($user)->post("/stocktakes/{$id}/count", $lines)->assertForbidden();
        $this->actingAs($user)->post("/stocktakes/{$id}/submit")->assertForbidden();
        $this->actingAs($user)->post("/stocktakes/{$id}/cancel")->assertForbidden();
    }

    // Manager boleh melihat tetapi tidak boleh menghitung.
    $this->actingAs($w->manager)->get("/stocktakes/{$id}")->assertOk();
    $this->actingAs($w->manager)->post("/stocktakes/{$id}/count", $lines)->assertForbidden();

    expect($stocktake->fresh()->items()->first()->counted_qty)->toBeNull()
        ->and($stocktake->fresh()->status)->toBe(StocktakeStatus::Counting);
});

it('does not let the submitter approve their own count, but lets another reviewer', function () {
    $w = shWorld();
    shSeed($w, $w->a, $w->p1, '10');
    $stocktake = shCounting($w);
    $id = $stocktake->id;

    $this->actingAs($w->admin)->post("/stocktakes/{$id}/count", shLines($stocktake, [$w->p1->id => ['8', 'rusak']]));
    $this->actingAs($w->admin)->post("/stocktakes/{$id}/submit")->assertSessionHas('success');

    $this->actingAs($w->admin)->post("/stocktakes/{$id}/approve")->assertForbidden();
    $this->actingAs($w->staffA)->post("/stocktakes/{$id}/approve")->assertForbidden();

    expect(shBalance($w->a, $w->p1))->toBe(10.0)
        ->and(StockMovement::where('movement_type', MovementType::Adjustment)->count())->toBe(0);

    $this->actingAs($w->manager)->post("/stocktakes/{$id}/approve")->assertSessionHas('success');

    expect(shBalance($w->a, $w->p1))->toBe(8.0);
});

it('requires a note to reject and returns the stocktake to counting', function () {
    $w = shWorld();
    shSeed($w, $w->a, $w->p1, '10');
    $stocktake = shCounting($w);
    $id = $stocktake->id;

    $this->actingAs($w->staffA)->post("/stocktakes/{$id}/count", shLines($stocktake, [$w->p1->id => ['8', 'rusak']]));
    $this->actingAs($w->staffA)->post("/stocktakes/{$id}/submit");

    $this->actingAs($w->staffA)->post("/stocktakes/{$id}/reject", ['note' => 'x'])->assertForbidden();
    $this->actingAs($w->manager)->post("/stocktakes/{$id}/reject", ['note' => ''])->assertSessionHasErrors('note');
    expect($stocktake->fresh()->status)->toBe(StocktakeStatus::PendingApproval);

    $this->actingAs($w->manager)->post("/stocktakes/{$id}/reject", ['note' => 'Hitung ulang rak 3'])
        ->assertSessionHas('success');

    $fresh = $stocktake->fresh();

    expect($fresh->status)->toBe(StocktakeStatus::Counting)
        ->and($fresh->rejection_note)->toBe('Hitung ulang rak 3')
        ->and((float) $fresh->items()->first()->counted_qty)->toBe(8.0);
});

it('blocks stock posting over HTTP while the warehouse is counted and shares the lock with the UI', function () {
    $w = shWorld();
    shSeed($w, $w->a, $w->p1, '10');
    shCounting($w);

    $receipt = shReceipt($w, $w->a, $w->p1, '5');

    $this->actingAs($w->staffA)
        ->post("/goods-receipts/{$receipt->id}/post")
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(shBalance($w->a, $w->p1))->toBe(10.0)
        ->and($receipt->fresh()->status)->toBe(DocumentStatus::Draft);

    $this->actingAs($w->staffA)->get('/goods-receipts')->assertInertia(fn (Assert $page) => $page
        ->has('lockedWarehouses', 1)
        ->where('lockedWarehouses.0.code', 'GD-A'));

    // Staff gudang lain tidak melihat banner untuk gudang yang bukan miliknya; manager melihat.
    $this->actingAs($w->staffB)->get('/goods-receipts')
        ->assertInertia(fn (Assert $page) => $page->has('lockedWarehouses', 0));
    $this->actingAs($w->manager)->get('/goods-receipts')
        ->assertInertia(fn (Assert $page) => $page->has('lockedWarehouses', 1));
});

it('scopes the list and exposes only the allowed buttons', function () {
    $w = shWorld();
    $stocktake = shDraft($w);

    $this->actingAs($w->staffA)->get('/stocktakes')->assertInertia(fn (Assert $page) => $page
        ->component('stocktakes/index')
        ->has('stocktakes.data', 1));

    $this->actingAs($w->staffB)->get('/stocktakes')
        ->assertInertia(fn (Assert $page) => $page->has('stocktakes.data', 0));
    $this->actingAs($w->manager)->get('/stocktakes')
        ->assertInertia(fn (Assert $page) => $page->has('stocktakes.data', 1));

    $this->actingAs($w->staffA)->get("/stocktakes/{$stocktake->id}")->assertInertia(fn (Assert $page) => $page
        ->component('stocktakes/show')
        ->where('can.start', true)
        ->where('can.update', true)
        ->where('can.approve', false));

    $this->actingAs($w->manager)->get("/stocktakes/{$stocktake->id}")->assertInertia(fn (Assert $page) => $page
        ->where('can.start', false)
        ->where('can.update', false));
});

it('updates and deletes a draft only while it is still a draft', function () {
    $w = shWorld();
    shSeed($w, $w->a, $w->p1, '10');
    $stocktake = shDraft($w);
    $id = $stocktake->id;

    $this->actingAs($w->staffA)
        ->put("/stocktakes/{$id}", ['warehouse_id' => $w->a->id, 'notes' => 'baru', 'product_ids' => [$w->p2->id]])
        ->assertRedirect();

    expect($stocktake->fresh()->notes)->toBe('baru')
        ->and($stocktake->fresh()->items->pluck('product_id')->all())->toBe([$w->p2->id]);

    $this->actingAs($w->staffA)->post("/stocktakes/{$id}/start")->assertSessionHas('success');

    $this->actingAs($w->staffA)->put("/stocktakes/{$id}", ['warehouse_id' => $w->a->id])->assertForbidden();
    $this->actingAs($w->staffA)->delete("/stocktakes/{$id}")->assertForbidden();

    expect(Stocktake::count())->toBe(1);

    $other = shDraft($w);
    $this->actingAs($w->staffA)->delete("/stocktakes/{$other->id}")->assertRedirect();

    expect(Stocktake::count())->toBe(1);
});

it('cancels over HTTP and releases the warehouse', function () {
    $w = shWorld();
    shSeed($w, $w->a, $w->p1, '10');
    $stocktake = shCounting($w);

    $this->actingAs($w->staffA)->post("/stocktakes/{$stocktake->id}/cancel")->assertSessionHas('success');

    expect($stocktake->fresh()->status)->toBe(StocktakeStatus::Cancelled);

    $receipt = shReceipt($w, $w->a, $w->p1, '1');
    $this->actingAs($w->staffA)->post("/goods-receipts/{$receipt->id}/post")->assertSessionHas('success');

    expect(shBalance($w->a, $w->p1))->toBe(11.0);
});

it('redirects guests away from stocktakes', function () {
    $this->get('/stocktakes')->assertRedirect('/login');
    $this->post('/stocktakes', [])->assertRedirect('/login');
});
