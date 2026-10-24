<?php

use App\Actions\Inventory\PostGoodsReceipt;
use App\Enums\MovementType;
use App\Enums\Role;
use App\Enums\TransferStatus;
use App\Models\Category;
use App\Models\GoodsReceipt;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function thUser(Role $role, array $warehouseIds = []): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();
    $user->warehouses()->attach($warehouseIds);

    return $user->fresh();
}

function thWorld(): object
{
    config(['stockpilot.transfer_requires_approval' => true]);

    $category = Category::create(['name' => 'Cat', 'slug' => 'cat']);
    $unit = Unit::create(['name' => 'Pieces', 'symbol' => 'pcs']);
    $a = Warehouse::create(['code' => 'GD-A', 'name' => 'A']);
    $b = Warehouse::create(['code' => 'GD-B', 'name' => 'B']);

    return (object) [
        'a' => $a,
        'b' => $b,
        'p1' => Product::create(['category_id' => $category->id, 'unit_id' => $unit->id, 'sku' => 'SKU-1', 'name' => 'SKU-1']),
        'p2' => Product::create(['category_id' => $category->id, 'unit_id' => $unit->id, 'sku' => 'SKU-2', 'name' => 'SKU-2']),
        'admin' => thUser(Role::Admin),
        'manager' => thUser(Role::Manager),
        'staffA' => thUser(Role::WarehouseStaff, [$a->id]),
        'staffB' => thUser(Role::WarehouseStaff, [$b->id]),
        'outsider' => thUser(Role::WarehouseStaff),
    ];
}

function thSeed(object $w, Warehouse $warehouse, Product $product, string $qty): void
{
    $receipt = GoodsReceipt::create(['warehouse_id' => $warehouse->id, 'created_by' => $w->admin->id]);
    $receipt->items()->create(['product_id' => $product->id, 'quantity' => $qty]);
    app(PostGoodsReceipt::class)->execute($receipt, $w->admin);
}

function thDraft(object $w, string $qty = '8'): StockTransfer
{
    $transfer = StockTransfer::create([
        'source_warehouse_id' => $w->a->id,
        'destination_warehouse_id' => $w->b->id,
        'created_by' => $w->staffA->id,
    ]);
    $transfer->items()->create(['product_id' => $w->p1->id, 'quantity' => $qty]);

    return $transfer;
}

function thPayload(object $w, ?array $items = null): array
{
    return [
        'source_warehouse_id' => $w->a->id,
        'destination_warehouse_id' => $w->b->id,
        'reference' => 'REQ-1',
        'items' => $items ?? [['product_id' => $w->p1->id, 'quantity' => '8']],
    ];
}

function thStock(Warehouse $warehouse, Product $product): float
{
    return (float) InventoryStock::where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->value('quantity');
}

it('lets assigned staff create a draft transfer without moving stock', function () {
    $w = thWorld();
    thSeed($w, $w->a, $w->p1, '10');

    $this->actingAs($w->staffA)->post('/transfers', thPayload($w))->assertRedirect();

    $transfer = StockTransfer::first();

    expect($transfer->status)->toBe(TransferStatus::Draft)
        ->and($transfer->number)->toStartWith('TR-')
        ->and($transfer->items)->toHaveCount(1)
        ->and(thStock($w->a, $w->p1))->toBe(10.0)
        ->and(StockMovement::where('movement_type', MovementType::TransferOut)->count())->toBe(0);
});

it('rejects a source warehouse the user is not assigned to', function () {
    $w = thWorld();

    $this->actingAs($w->staffB)->post('/transfers', thPayload($w))->assertForbidden();
    $this->actingAs($w->manager)->post('/transfers', thPayload($w))->assertForbidden();

    expect(StockTransfer::count())->toBe(0);
});

it('rejects the same source and destination', function () {
    $w = thWorld();

    $this->actingAs($w->staffA)
        ->post('/transfers', [...thPayload($w), 'destination_warehouse_id' => $w->a->id])
        ->assertSessionHasErrors('destination_warehouse_id');

    expect(StockTransfer::count())->toBe(0);
});

it('rejects invalid item lines', function () {
    $w = thWorld();

    $cases = [
        ['items.0.quantity', [['product_id' => $w->p1->id, 'quantity' => '0']]],
        ['items.0.quantity', [['product_id' => $w->p1->id, 'quantity' => '-5']]],
        ['items.1.product_id', [
            ['product_id' => $w->p1->id, 'quantity' => '1'],
            ['product_id' => $w->p1->id, 'quantity' => '2'],
        ]],
        ['items', []],
    ];

    foreach ($cases as [$errorKey, $items]) {
        $this->actingAs($w->staffA)
            ->post('/transfers', thPayload($w, $items))
            ->assertSessionHasErrors($errorKey);
    }

    expect(StockTransfer::count())->toBe(0);
});

it('runs the full transfer flow over HTTP', function () {
    $w = thWorld();
    thSeed($w, $w->a, $w->p1, '10');
    $transfer = thDraft($w, '8');
    $id = $transfer->id;

    $this->actingAs($w->staffA)->post("/transfers/{$id}/submit")->assertRedirect();
    expect($transfer->fresh()->status)->toBe(TransferStatus::PendingApproval);

    $this->actingAs($w->manager)->post("/transfers/{$id}/approve")->assertRedirect();
    expect($transfer->fresh()->status)->toBe(TransferStatus::Approved);

    $this->actingAs($w->staffA)->post("/transfers/{$id}/dispatch")->assertRedirect();
    expect($transfer->fresh()->status)->toBe(TransferStatus::InTransit)
        ->and(thStock($w->a, $w->p1))->toBe(2.0)
        ->and(thStock($w->b, $w->p1))->toBe(0.0);

    $item = $transfer->items()->first();
    $receive = fn (string $key, string $qty) => $this->actingAs($w->staffB)->post("/transfers/{$id}/receive", [
        'idempotency_key' => $key,
        'lines' => [['item_id' => $item->id, 'quantity' => $qty]],
    ]);

    $receive('k-1', '5')->assertRedirect()->assertSessionHas('success');
    expect($transfer->fresh()->status)->toBe(TransferStatus::PartiallyReceived)
        ->and(thStock($w->b, $w->p1))->toBe(5.0);

    // Kirim ulang dengan key yang sama: ditolak, stok tidak bertambah dua kali.
    $receive('k-1', '5')->assertRedirect()->assertSessionHas('error');
    expect(thStock($w->b, $w->p1))->toBe(5.0)
        ->and(StockMovement::where('movement_type', MovementType::TransferIn)->count())->toBe(1);

    $this->actingAs($w->staffA)->post("/transfers/{$id}/return")->assertRedirect()->assertSessionHas('success');

    expect($transfer->fresh()->status)->toBe(TransferStatus::Received)
        ->and(thStock($w->a, $w->p1))->toBe(5.0)
        ->and(thStock($w->b, $w->p1))->toBe(5.0);
});

it('keeps the transfer approved and reports an error when dispatch has insufficient stock', function () {
    $w = thWorld();
    thSeed($w, $w->a, $w->p1, '2');
    $transfer = thDraft($w, '5');

    $this->actingAs($w->staffA)->post("/transfers/{$transfer->id}/submit");
    $this->actingAs($w->manager)->post("/transfers/{$transfer->id}/approve");

    $this->actingAs($w->staffA)
        ->post("/transfers/{$transfer->id}/dispatch")
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($transfer->fresh()->status)->toBe(TransferStatus::Approved)
        ->and(thStock($w->a, $w->p1))->toBe(2.0)
        ->and(StockMovement::where('movement_type', MovementType::TransferOut)->count())->toBe(0);
});

it('enforces who may approve, dispatch and receive', function () {
    $w = thWorld();
    thSeed($w, $w->a, $w->p1, '10');
    $transfer = thDraft($w, '4');
    $id = $transfer->id;

    $this->actingAs($w->staffA)->post("/transfers/{$id}/submit")->assertRedirect();

    // Staff tidak boleh approve; manager boleh.
    $this->actingAs($w->staffA)->post("/transfers/{$id}/approve")->assertForbidden();
    $this->actingAs($w->staffB)->post("/transfers/{$id}/approve")->assertForbidden();
    $this->actingAs($w->manager)->post("/transfers/{$id}/approve")->assertRedirect();

    // Hanya gudang sumber yang boleh kirim.
    $this->actingAs($w->staffB)->post("/transfers/{$id}/dispatch")->assertForbidden();
    $this->actingAs($w->manager)->post("/transfers/{$id}/dispatch")->assertForbidden();
    $this->actingAs($w->outsider)->post("/transfers/{$id}/dispatch")->assertForbidden();
    $this->actingAs($w->staffA)->post("/transfers/{$id}/dispatch")->assertRedirect();

    // Hanya gudang tujuan yang boleh menerima.
    $item = $transfer->items()->first();
    $body = ['idempotency_key' => 'x', 'lines' => [['item_id' => $item->id, 'quantity' => '1']]];

    $this->actingAs($w->staffA)->post("/transfers/{$id}/receive", $body)->assertForbidden();
    $this->actingAs($w->outsider)->post("/transfers/{$id}/receive", $body)->assertForbidden();

    expect(StockMovement::where('movement_type', MovementType::TransferIn)->count())->toBe(0);

    // Hanya gudang sumber yang boleh meretur sisa.
    $this->actingAs($w->staffB)->post("/transfers/{$id}/return")->assertForbidden();
});

it('rejects invalid receive input', function () {
    $w = thWorld();
    thSeed($w, $w->a, $w->p1, '10');
    $transfer = thDraft($w, '4');
    $id = $transfer->id;

    $this->actingAs($w->staffA)->post("/transfers/{$id}/submit");
    $this->actingAs($w->manager)->post("/transfers/{$id}/approve");
    $this->actingAs($w->staffA)->post("/transfers/{$id}/dispatch");

    $item = $transfer->items()->first();
    $post = fn (array $body) => $this->actingAs($w->staffB)->post("/transfers/{$id}/receive", $body);

    $post(['lines' => [['item_id' => $item->id, 'quantity' => '1']]])->assertSessionHasErrors('idempotency_key');
    $post(['idempotency_key' => 'k', 'lines' => []])->assertSessionHasErrors('lines');
    $post(['idempotency_key' => 'k', 'lines' => [['item_id' => $item->id, 'quantity' => '0']]])
        ->assertSessionHasErrors('lines.0.quantity');

    // Melebihi sisa: lolos validasi format, ditolak action sebagai error bisnis.
    $post(['idempotency_key' => 'k', 'lines' => [['item_id' => $item->id, 'quantity' => '99']]])
        ->assertSessionHas('error');

    expect(thStock($w->b, $w->p1))->toBe(0.0)
        ->and(StockMovement::where('movement_type', MovementType::TransferIn)->count())->toBe(0)
        ->and($transfer->fresh()->status)->toBe(TransferStatus::InTransit);
});

it('scopes the list and the detail page to involved warehouses', function () {
    $w = thWorld();
    $transfer = thDraft($w);

    $this->actingAs($w->staffA)->get('/transfers')->assertInertia(fn (Assert $page) => $page
        ->component('transfers/index')
        ->has('transfers.data', 1));

    $this->actingAs($w->staffB)->get('/transfers')
        ->assertInertia(fn (Assert $page) => $page->has('transfers.data', 1));

    $this->actingAs($w->outsider)->get('/transfers')
        ->assertInertia(fn (Assert $page) => $page->has('transfers.data', 0));

    $this->actingAs($w->manager)->get('/transfers')
        ->assertInertia(fn (Assert $page) => $page->has('transfers.data', 1));

    $this->actingAs($w->outsider)->get("/transfers/{$transfer->id}")->assertForbidden();
    $this->actingAs($w->staffB)->get("/transfers/{$transfer->id}")->assertOk();
});

it('shows only the buttons the user is allowed to use', function () {
    $w = thWorld();
    $transfer = thDraft($w);
    $this->actingAs($w->staffA)->post("/transfers/{$transfer->id}/submit");

    $this->actingAs($w->manager)->get("/transfers/{$transfer->id}")->assertInertia(fn (Assert $page) => $page
        ->component('transfers/show')
        ->where('can.approve', true)
        ->where('can.dispatch', false)
        ->where('can.update', false));

    $this->actingAs($w->staffA)->get("/transfers/{$transfer->id}")->assertInertia(fn (Assert $page) => $page
        ->where('can.approve', false)
        ->where('can.cancel', true));
});

it('updates a draft but refuses once it is submitted', function () {
    $w = thWorld();
    $transfer = thDraft($w);

    $payload = thPayload($w, [['product_id' => $w->p2->id, 'quantity' => '3']]);

    $this->actingAs($w->staffA)->put("/transfers/{$transfer->id}", $payload)->assertRedirect();
    expect($transfer->fresh()->items->pluck('product_id')->all())->toBe([$w->p2->id]);

    $this->actingAs($w->staffA)->post("/transfers/{$transfer->id}/submit");

    $this->actingAs($w->staffA)->put("/transfers/{$transfer->id}", $payload)->assertForbidden();
    $this->actingAs($w->staffA)->delete("/transfers/{$transfer->id}")->assertForbidden();

    expect(StockTransfer::count())->toBe(1);
});

it('deletes a draft together with its items', function () {
    $w = thWorld();
    $transfer = thDraft($w);

    $this->actingAs($w->staffA)->delete("/transfers/{$transfer->id}")->assertRedirect();

    expect(StockTransfer::count())->toBe(0)
        ->and(\App\Models\StockTransferItem::count())->toBe(0);
});

it('redirects guests away from transfers', function () {
    $this->get('/transfers')->assertRedirect('/login');
    $this->post('/transfers', [])->assertRedirect('/login');
});
it('renders the create and edit forms', function () {
    $w = thWorld();
    $transfer = thDraft($w);

    $this->actingAs($w->staffA)->get('/transfers/create')->assertInertia(fn (Assert $page) => $page
        ->component('transfers/form')
        ->where('document', null)
        ->has('sources', 1)
        ->has('destinations', 2));

    $this->actingAs($w->staffA)->get("/transfers/{$transfer->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->component('transfers/form')
        ->where('document.id', $transfer->id)
        ->has('document.items', 1));

    $this->actingAs($w->manager)->get('/transfers/create')->assertForbidden();
});
