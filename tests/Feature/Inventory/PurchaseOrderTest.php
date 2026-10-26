<?php

use App\Actions\Inventory\PostGoodsReceipt;
use App\Actions\Inventory\PostStockIssue;
use App\Actions\Inventory\ReverseStockDocument;
use App\Actions\Purchasing\ApprovePurchaseOrder;
use App\Actions\Purchasing\CancelPurchaseOrder;
use App\Actions\Purchasing\ClosePurchaseOrder;
use App\Actions\Purchasing\CreateReceiptFromPurchaseOrder;
use App\Actions\Purchasing\SubmitPurchaseOrder;
use App\Enums\DocumentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\Role;
use App\Exceptions\InvalidPurchaseOrderStateException;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\GoodsReceipt;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockIssue;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function poUser(Role $role, array $warehouseIds = []): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();
    $user->warehouses()->attach($warehouseIds);

    return $user->fresh();
}

function poWorld(): object
{
    config(['stockpilot.po_requires_approval' => true]);

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
        'supplier' => Supplier::create(['name' => 'PT Pemasok']),
        'p1' => $make('SKU-1'),
        'p2' => $make('SKU-2'),
        'p3' => $make('SKU-3'),
        'admin' => poUser(Role::Admin),
        'manager' => poUser(Role::Manager),
        'staffA' => poUser(Role::WarehouseStaff, [$a->id]),
        'staffB' => poUser(Role::WarehouseStaff, [$b->id]),
    ];
}

/** @param array<int, array{0: Product, 1: string, 2: string}>|null $lines [produk, jumlah, harga] */
function poDraft(object $w, ?array $lines = null): PurchaseOrder
{
    $order = PurchaseOrder::create([
        'supplier_id' => $w->supplier->id,
        'warehouse_id' => $w->a->id,
        'created_by' => $w->staffA->id,
    ]);

    foreach ($lines ?? [[$w->p1, '10', '100']] as [$product, $qty, $cost]) {
        $order->items()->create(['product_id' => $product->id, 'quantity' => $qty, 'unit_cost' => $cost]);
    }

    return $order;
}

function poApproved(object $w, ?array $lines = null): PurchaseOrder
{
    $order = poDraft($w, $lines);
    app(SubmitPurchaseOrder::class)->execute($order, $w->staffA);
    app(ApprovePurchaseOrder::class)->execute($order, $w->manager);

    return $order->fresh();
}

function poReceipt(object $w, PurchaseOrder $order, ?string $qty = null): GoodsReceipt
{
    $receipt = app(CreateReceiptFromPurchaseOrder::class)->execute($order->fresh(), $w->staffA);

    if ($qty !== null) {
        $receipt->items()->update(['quantity' => $qty]);
    }

    return $receipt->fresh();
}

function poPost(object $w, GoodsReceipt $receipt): GoodsReceipt
{
    return app(PostGoodsReceipt::class)->execute($receipt->fresh(), $w->staffA);
}

function poLine(PurchaseOrder $order, Product $product): PurchaseOrderItem
{
    return $order->items()->where('product_id', $product->id)->firstOrFail();
}

/** @return array{0: float, 1: string} kuantitas dan nilai */
function poStock(Warehouse $warehouse, Product $product): array
{
    $stock = InventoryStock::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();

    return $stock ? [(float) $stock->quantity, (string) $stock->total_value] : [0.0, '0.0000'];
}

function poPayload(object $w, ?array $items = null): array
{
    return [
        'supplier_id' => $w->supplier->id,
        'warehouse_id' => $w->a->id,
        'expected_date' => '2026-12-01',
        'reference' => 'Penawaran 12',
        'items' => $items ?? [['product_id' => $w->p1->id, 'quantity' => '10', 'unit_cost' => '100']],
    ];
}

it('goes to submitted or straight to approved depending on config', function () {
    $w = poWorld();

    $one = poDraft($w);
    app(SubmitPurchaseOrder::class)->execute($one, $w->staffA);

    expect($one->fresh()->status)->toBe(PurchaseOrderStatus::Submitted)
        ->and($one->fresh()->submitted_by)->toBe($w->staffA->id)
        ->and($one->fresh()->number)->toStartWith('PO-')
        ->and(AuditLog::where('action', 'purchase_order.submitted')->count())->toBe(1);

    config(['stockpilot.po_requires_approval' => false]);
    $two = poDraft($w);
    app(SubmitPurchaseOrder::class)->execute($two, $w->staffA);

    expect($two->fresh()->status)->toBe(PurchaseOrderStatus::Approved)
        ->and($two->fresh()->approved_by)->toBeNull()
        ->and($two->fresh()->approved_at)->not->toBeNull();
});

it('separates duties: the submitter cannot approve, a reviewer can, and only once', function () {
    $w = poWorld();
    $order = poDraft($w);
    app(SubmitPurchaseOrder::class)->execute($order, $w->admin);
    $order = $order->fresh();

    expect($w->admin->can('approve', $order))->toBeFalse()
        ->and($w->staffA->can('approve', $order))->toBeFalse()
        ->and($w->manager->can('approve', $order))->toBeTrue();

    app(ApprovePurchaseOrder::class)->execute($order, $w->manager);

    expect($order->fresh()->approved_by)->toBe($w->manager->id)
        ->and(fn () => app(ApprovePurchaseOrder::class)->execute($order, $w->manager))
        ->toThrow(InvalidPurchaseOrderStateException::class);
});

it('refuses to submit an empty order, or one with an inactive supplier or product', function () {
    $w = poWorld();
    $submit = fn (PurchaseOrder $o) => fn () => app(SubmitPurchaseOrder::class)->execute($o, $w->staffA);

    $empty = PurchaseOrder::create(['supplier_id' => $w->supplier->id, 'warehouse_id' => $w->a->id, 'created_by' => $w->staffA->id]);
    expect($submit($empty))->toThrow(DomainException::class);

    $order = poDraft($w);

    $w->p1->update(['is_active' => false]);
    expect($submit($order))->toThrow(DomainException::class);
    $w->p1->update(['is_active' => true]);

    $w->supplier->update(['is_active' => false]);
    expect($submit($order))->toThrow(DomainException::class);

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($empty->fresh()->status)->toBe(PurchaseOrderStatus::Draft);
});

it('prefills a draft receipt from the outstanding order lines and allows only one open draft', function () {
    $w = poWorld();
    $order = poApproved($w, [[$w->p1, '10', '100'], [$w->p2, '4', '25.5']]);

    $receipt = poReceipt($w, $order);
    $second = $receipt->items->firstWhere('product_id', $w->p2->id);

    expect($receipt->status)->toBe(DocumentStatus::Draft)
        ->and($receipt->purchase_order_id)->toBe($order->id)
        ->and($receipt->warehouse_id)->toBe($w->a->id)
        ->and($receipt->supplier_id)->toBe($w->supplier->id)
        ->and($receipt->reference)->toBe($order->number)
        ->and($receipt->items)->toHaveCount(2)
        ->and((float) $second->quantity)->toBe(4.0)
        ->and($second->unit_cost)->toBe('25.5000')
        ->and(InventoryStock::count())->toBe(0);

    expect(fn () => app(CreateReceiptFromPurchaseOrder::class)->execute($order->fresh(), $w->staffA))
        ->toThrow(DomainException::class, 'draft');

    $notApproved = poDraft($w);

    expect(fn () => app(CreateReceiptFromPurchaseOrder::class)->execute($notApproved, $w->staffA))
        ->toThrow(InvalidPurchaseOrderStateException::class);
});

it('receives a whole order, values it at the order cost and completes it', function () {
    $w = poWorld();
    $order = poApproved($w, [[$w->p1, '10', '100']]);

    $receipt = poPost($w, poReceipt($w, $order));
    $order = $order->fresh();
    [$qty, $value] = poStock($w->a, $w->p1);

    expect($qty)->toBe(10.0)
        ->and($value)->toBe('1000.0000')
        ->and($order->status)->toBe(PurchaseOrderStatus::Received)
        ->and($order->completed_at)->not->toBeNull()
        ->and((float) poLine($order, $w->p1)->qty_received)->toBe(10.0)
        ->and($receipt->fresh()->status)->toBe(DocumentStatus::Posted);

    $log = AuditLog::where('action', 'goods_receipt.posted')->first();

    expect($log->metadata['purchase_order'])->toBe($order->number)
        ->and($log->metadata['purchase_order_status'])->toBe('received');
});

it('supports partial receipts until the order is complete', function () {
    $w = poWorld();
    $order = poApproved($w, [[$w->p1, '10', '100']]);

    poPost($w, poReceipt($w, $order, '4'));
    $partial = $order->fresh();

    expect($partial->status)->toBe(PurchaseOrderStatus::PartiallyReceived)
        ->and($partial->completed_at)->toBeNull()
        ->and((float) poLine($partial, $w->p1)->qty_received)->toBe(4.0)
        ->and(poStock($w->a, $w->p1)[0])->toBe(4.0);

    $next = poReceipt($w, $order); // terisi otomatis dengan sisa pesanan

    expect((float) $next->items->first()->quantity)->toBe(6.0);

    poPost($w, $next);

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and(poStock($w->a, $w->p1))->toBe([10.0, '1000.0000']);
});

it('rejects receiving more than the outstanding quantity and changes nothing', function () {
    $w = poWorld();
    $order = poApproved($w, [[$w->p1, '10', '100']]);

    $receipt = poReceipt($w, $order, '11');

    expect(fn () => poPost($w, $receipt))->toThrow(DomainException::class, 'melebihi');

    expect(poStock($w->a, $w->p1)[0])->toBe(0.0)
        ->and(StockMovement::count())->toBe(0)
        ->and($order->fresh()->status)->toBe(PurchaseOrderStatus::Approved)
        ->and((float) poLine($order, $w->p1)->qty_received)->toBe(0.0)
        ->and($receipt->fresh()->status)->toBe(DocumentStatus::Draft);

    // Sesudah penerimaan sebagian, batasnya adalah sisa, bukan jumlah pesanan.
    $receipt->items()->update(['quantity' => '4']);
    poPost($w, $receipt);

    $next = poReceipt($w, $order, '7'); // sisa hanya 6

    expect(fn () => poPost($w, $next))->toThrow(DomainException::class, 'melebihi')
        ->and(poStock($w->a, $w->p1)[0])->toBe(4.0)
        ->and((float) poLine($order->fresh(), $w->p1)->qty_received)->toBe(4.0);
});

it('rejects a receipt whose price differs from the order', function () {
    $w = poWorld();
    $order = poApproved($w);
    $receipt = poReceipt($w, $order);
    $receipt->items()->update(['unit_cost' => '1']);

    expect(fn () => poPost($w, $receipt))->toThrow(DomainException::class, 'berbeda dari');

    expect(StockMovement::count())->toBe(0)->and($order->fresh()->status)->toBe(PurchaseOrderStatus::Approved);
});

it('refuses to post a receipt once its order is cancelled', function () {
    $w = poWorld();
    $order = poApproved($w);
    $receipt = poReceipt($w, $order);

    app(CancelPurchaseOrder::class)->execute($order->fresh(), $w->manager);

    expect(fn () => poPost($w, $receipt))->toThrow(DomainException::class, 'tidak dapat menerima');

    expect(poStock($w->a, $w->p1)[0])->toBe(0.0)->and($receipt->fresh()->status)->toBe(DocumentStatus::Draft);
});

it('cancels before any receipt and never after goods were received', function () {
    $w = poWorld();

    $draft = poDraft($w);
    app(CancelPurchaseOrder::class)->execute($draft, $w->staffA);

    expect($draft->fresh()->status)->toBe(PurchaseOrderStatus::Cancelled)
        ->and($draft->fresh()->completed_at)->not->toBeNull();

    $approved = poApproved($w);
    app(CancelPurchaseOrder::class)->execute($approved, $w->manager);

    expect($approved->fresh()->status)->toBe(PurchaseOrderStatus::Cancelled);

    $partial = poApproved($w, [[$w->p1, '10', '100']]);
    poPost($w, poReceipt($w, $partial, '4'));

    expect(fn () => app(CancelPurchaseOrder::class)->execute($partial->fresh(), $w->manager))
        ->toThrow(InvalidPurchaseOrderStateException::class)
        ->and($w->manager->can('cancel', $partial->fresh()))->toBeFalse()
        ->and($partial->fresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived);
});

it('closes a partially received order with a reason and blocks further receipts', function () {
    $w = poWorld();
    $order = poApproved($w, [[$w->p1, '10', '100']]);
    poPost($w, poReceipt($w, $order, '4'));

    expect(fn () => app(ClosePurchaseOrder::class)->execute($order->fresh(), $w->manager, '  '))
        ->toThrow(DomainException::class);

    app(ClosePurchaseOrder::class)->execute($order->fresh(), $w->manager, 'Supplier tidak sanggup');
    $closed = $order->fresh();

    expect($closed->status)->toBe(PurchaseOrderStatus::Received)
        ->and($closed->close_reason)->toBe('Supplier tidak sanggup')
        ->and($closed->closed_by)->toBe($w->manager->id)
        ->and($closed->completed_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'purchase_order.closed')->first()->metadata['lines_not_fulfilled'])->toBe(1)
        ->and(fn () => app(CreateReceiptFromPurchaseOrder::class)->execute($closed, $w->staffA))
        ->toThrow(InvalidPurchaseOrderStateException::class);

    $untouched = poApproved($w);

    expect(fn () => app(ClosePurchaseOrder::class)->execute($untouched, $w->manager, 'Alasan'))
        ->toThrow(InvalidPurchaseOrderStateException::class);
});

it('reopens the order when its receipt is reversed', function () {
    $w = poWorld();
    $order = poApproved($w, [[$w->p1, '10', '100']]);
    $receipt = poPost($w, poReceipt($w, $order));

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Received);

    app(ReverseStockDocument::class)->execute($receipt, $w->manager, 'Salah input');
    $reopened = $order->fresh();

    expect($reopened->status)->toBe(PurchaseOrderStatus::Approved)
        ->and($reopened->completed_at)->toBeNull()
        ->and((float) poLine($reopened, $w->p1)->qty_received)->toBe(0.0)
        ->and(poStock($w->a, $w->p1))->toBe([0.0, '0.0000'])
        ->and(AuditLog::where('action', 'goods_receipt.reversed')->first()->metadata['purchase_order_status'])->toBe('approved');
});

it('recomputes the order status when only one of several receipts is reversed', function () {
    $w = poWorld();
    $order = poApproved($w, [[$w->p1, '10', '100']]);
    $first = poPost($w, poReceipt($w, $order, '4'));
    $second = poPost($w, poReceipt($w, $order, '6'));

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Received);

    app(ReverseStockDocument::class)->execute($first, $w->manager, 'Salah input');

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived)
        ->and((float) poLine($order, $w->p1)->qty_received)->toBe(6.0)
        ->and(poStock($w->a, $w->p1)[0])->toBe(6.0);

    app(ReverseStockDocument::class)->execute($second, $w->manager, 'Salah input');

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Approved)
        ->and((float) poLine($order, $w->p1)->qty_received)->toBe(0.0)
        ->and(poStock($w->a, $w->p1)[0])->toBe(0.0);
});

it('clears the closure when a receipt of a closed order is reversed', function () {
    $w = poWorld();
    $order = poApproved($w, [[$w->p1, '10', '100']]);
    $receipt = poPost($w, poReceipt($w, $order, '4'));
    app(ClosePurchaseOrder::class)->execute($order->fresh(), $w->manager, 'Supplier tidak sanggup');

    app(ReverseStockDocument::class)->execute($receipt, $w->manager, 'Salah input');
    $reopened = $order->fresh();

    expect($reopened->status)->toBe(PurchaseOrderStatus::Approved)
        ->and($reopened->close_reason)->toBeNull()
        ->and($reopened->closed_by)->toBeNull()
        ->and($reopened->closed_at)->toBeNull();
});

it('leaves the order untouched when a reversal is refused', function () {
    $w = poWorld();
    $order = poApproved($w, [[$w->p1, '10', '100']]);
    $receipt = poPost($w, poReceipt($w, $order));

    $issue = StockIssue::create(['warehouse_id' => $w->a->id, 'reason' => 'Jual', 'created_by' => $w->admin->id]);
    $issue->items()->create(['product_id' => $w->p1->id, 'quantity' => '8']);
    app(PostStockIssue::class)->execute($issue, $w->admin);

    expect(fn () => app(ReverseStockDocument::class)->execute($receipt, $w->manager, 'Salah input'))
        ->toThrow(DomainException::class, 'sudah terpakai');

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and((float) poLine($order, $w->p1)->qty_received)->toBe(10.0);
});

it('locks the planned lines and the parties once an order leaves draft', function () {
    $w = poWorld();
    $order = poApproved($w);

    expect(fn () => $order->items()->first()->update(['quantity' => 99]))->toThrow(LogicException::class)
        ->and(fn () => $order->items()->first()->update(['unit_cost' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $order->items()->first()->delete())->toThrow(LogicException::class)
        ->and(fn () => $order->update(['supplier_id' => 999]))->toThrow(LogicException::class)
        ->and(fn () => $order->delete())->toThrow(LogicException::class);
});

it('lets assigned staff create a draft order and keeps other roles and warehouses out', function () {
    $w = poWorld();

    $this->actingAs($w->staffA)->post('/purchase-orders', poPayload($w))->assertRedirect();

    $order = PurchaseOrder::first();

    expect($order->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($order->number)->toStartWith('PO-')
        ->and($order->items)->toHaveCount(1)
        ->and($order->items->first()->unit_cost)->toBe('100.0000')
        ->and(InventoryStock::count())->toBe(0);

    $this->actingAs($w->staffB)->post('/purchase-orders', poPayload($w))->assertForbidden();
    $this->actingAs($w->manager)->post('/purchase-orders', poPayload($w))->assertForbidden();

    expect(PurchaseOrder::count())->toBe(1);
});

it('validates purchase orders', function () {
    $w = poWorld();
    $dead = Supplier::create(['name' => 'Tutup', 'is_active' => false]);
    $line = fn (array $override = []) => [['product_id' => $w->p1->id, 'quantity' => '1', 'unit_cost' => '1', ...$override]];

    $cases = [
        ['items.0.quantity', poPayload($w, $line(['quantity' => '0']))],
        ['items.0.quantity', poPayload($w, $line(['quantity' => '1.2345']))],
        ['items.0.unit_cost', poPayload($w, $line(['unit_cost' => '-1']))],
        ['items.0.unit_cost', poPayload($w, [['product_id' => $w->p1->id, 'quantity' => '1']])],
        ['items.1.product_id', poPayload($w, [...$line(), ...$line()])],
        ['items', poPayload($w, [])],
        ['supplier_id', [...poPayload($w), 'supplier_id' => $dead->id]],
        ['supplier_id', [...poPayload($w), 'supplier_id' => null]],
        ['expected_date', [...poPayload($w), 'expected_date' => '01/12/2026']],
    ];

    foreach ($cases as [$errorKey, $payload]) {
        $this->actingAs($w->staffA)->post('/purchase-orders', $payload)->assertSessionHasErrors($errorKey);
    }

    expect(PurchaseOrder::count())->toBe(0);
});

it('runs the whole purchase flow over HTTP and locks the receipt to the order', function () {
    $w = poWorld();

    $this->actingAs($w->staffA)->post('/purchase-orders', poPayload($w, [
        ['product_id' => $w->p1->id, 'quantity' => '10', 'unit_cost' => '100'],
        ['product_id' => $w->p2->id, 'quantity' => '5', 'unit_cost' => '40'],
    ]));

    $order = PurchaseOrder::first();
    $id = $order->id;

    $this->actingAs($w->staffA)->post("/purchase-orders/{$id}/submit")->assertSessionHas('success');
    $this->actingAs($w->staffA)->post("/purchase-orders/{$id}/approve")->assertForbidden();
    $this->actingAs($w->manager)->post("/purchase-orders/{$id}/approve")->assertSessionHas('success');

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Approved);

    $this->actingAs($w->staffB)->post("/purchase-orders/{$id}/receipts")->assertForbidden();
    $this->actingAs($w->manager)->post("/purchase-orders/{$id}/receipts")->assertForbidden();
    $this->actingAs($w->staffA)->post("/purchase-orders/{$id}/receipts")->assertRedirect();

    $receipt = GoodsReceipt::first();

    expect($receipt->purchase_order_id)->toBe($id);

    $put = fn (array $items) => $this->actingAs($w->staffA)->put("/goods-receipts/{$receipt->id}", [
        'warehouse_id' => $w->a->id,
        'supplier_id' => null,
        'items' => $items,
    ]);

    $put([['product_id' => $w->p3->id, 'quantity' => '1', 'unit_cost' => '1']])
        ->assertSessionHasErrors('items.0.product_id');
    $put([['product_id' => $w->p1->id, 'quantity' => '11', 'unit_cost' => '100']])
        ->assertSessionHasErrors('items.0.quantity');

    // Harga dari klien diabaikan, supplier dan gudang mengikuti PO.
    $put([['product_id' => $w->p1->id, 'quantity' => '6', 'unit_cost' => '1']])->assertRedirect();

    $saved = $receipt->fresh();
    $item = $saved->items()->first();

    expect($item->unit_cost)->toBe('100.0000')
        ->and((float) $item->quantity)->toBe(6.0)
        ->and($saved->supplier_id)->toBe($w->supplier->id);

    $this->actingAs($w->staffA)->post("/goods-receipts/{$receipt->id}/post")->assertSessionHas('success');

    $order = $order->fresh();

    expect($order->status)->toBe(PurchaseOrderStatus::PartiallyReceived)
        ->and((float) poLine($order, $w->p1)->qty_received)->toBe(6.0)
        ->and((float) poLine($order, $w->p2)->qty_received)->toBe(0.0)
        ->and(poStock($w->a, $w->p1)[1])->toBe('600.0000');

    $this->actingAs($w->manager)->post("/purchase-orders/{$id}/close", ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($w->staffA)->post("/purchase-orders/{$id}/close", ['reason' => 'Habis'])->assertForbidden();
    $this->actingAs($w->manager)->post("/purchase-orders/{$id}/close", ['reason' => 'Supplier kehabisan stok'])
        ->assertSessionHas('success');

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Received);
});

it('scopes the list and the detail page and exposes only the allowed buttons', function () {
    $w = poWorld();
    $order = poDraft($w);
    $id = $order->id;

    $this->actingAs($w->staffA)->get('/purchase-orders')->assertInertia(fn (Assert $page) => $page
        ->component('purchase-orders/index')
        ->has('orders.data', 1)
        ->where('orders.data.0.total_value', '1000.0000'));
    $this->actingAs($w->staffB)->get('/purchase-orders')->assertInertia(fn (Assert $page) => $page->has('orders.data', 0));
    $this->actingAs($w->manager)->get('/purchase-orders')->assertInertia(fn (Assert $page) => $page->has('orders.data', 1));

    $this->actingAs($w->staffB)->get("/purchase-orders/{$id}")->assertForbidden();

    $this->actingAs($w->staffA)->get("/purchase-orders/{$id}")->assertInertia(fn (Assert $page) => $page
        ->component('purchase-orders/show')
        ->where('can.update', true)
        ->where('can.submit', true)
        ->where('can.approve', false)
        ->where('document.total', '1000.0000'));

    $this->actingAs($w->staffA)->post("/purchase-orders/{$id}/submit");

    $this->actingAs($w->manager)->get("/purchase-orders/{$id}")->assertInertia(fn (Assert $page) => $page
        ->where('can.approve', true)
        ->where('can.update', false)
        ->where('can.receive', false));
});

it('renders the create and edit forms', function () {
    $w = poWorld();
    $order = poDraft($w);

    $this->actingAs($w->staffA)->get('/purchase-orders/create')->assertInertia(fn (Assert $page) => $page
        ->component('purchase-orders/form')
        ->where('document', null)
        ->has('warehouses', 1)
        ->has('suppliers', 1)
        ->has('products', 3));

    $this->actingAs($w->staffA)->get("/purchase-orders/{$order->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('document.id', $order->id)
        ->has('document.items', 1));

    $this->actingAs($w->manager)->get('/purchase-orders/create')->assertForbidden();
    $this->actingAs($w->staffB)->get("/purchase-orders/{$order->id}/edit")->assertForbidden();
});

it('exposes the order to the goods receipt form only for receipts created from it', function () {
    $w = poWorld();
    $order = poApproved($w);
    $linked = poReceipt($w, $order);

    $this->actingAs($w->staffA)->get("/goods-receipts/{$linked->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('purchase_order.number', $order->number)
        ->has('purchase_order.items', 1)
        ->where('purchase_order.items.0.outstanding', '10.000'));

    $plain = GoodsReceipt::create(['warehouse_id' => $w->a->id, 'created_by' => $w->staffA->id]);
    $plain->items()->create(['product_id' => $w->p1->id, 'quantity' => '1', 'unit_cost' => '1']);

    $this->actingAs($w->staffA)->get("/goods-receipts/{$plain->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('purchase_order', null));
});

it('lists orders waiting for approval or receipt on the dashboard', function () {
    $w = poWorld();
    $order = poDraft($w);
    app(SubmitPurchaseOrder::class)->execute($order, $w->staffA);

    $has = fn (string $href) => fn ($attention) => collect($attention)
        ->contains(fn ($row) => $row['href'] === $href && $row['count'] === 1);

    $this->actingAs($w->manager)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('attention', $has('/purchase-orders?status=submitted')));

    app(ApprovePurchaseOrder::class)->execute($order, $w->manager);

    $this->actingAs($w->staffA)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('attention', $has('/purchase-orders')));
});

it('redirects guests away from purchase orders', function () {
    $this->get('/purchase-orders')->assertRedirect('/login');
    $this->get('/purchase-orders/create')->assertRedirect('/login');
    $this->post('/purchase-orders', [])->assertRedirect('/login');
});
