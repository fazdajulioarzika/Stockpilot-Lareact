<?php

use App\Actions\Inventory\ApproveStockTransfer;
use App\Actions\Inventory\CancelStockTransfer;
use App\Actions\Inventory\DispatchStockTransfer;
use App\Actions\Inventory\PostGoodsReceipt;
use App\Actions\Inventory\ReceiveStockTransfer;
use App\Actions\Inventory\ReturnTransferRemainder;
use App\Actions\Inventory\SubmitStockTransfer;
use App\Enums\MovementType;
use App\Enums\Role;
use App\Enums\TransferStatus;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidTransferStateException;
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

uses(RefreshDatabase::class);

function tfUser(Role $role, array $warehouseIds = []): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();
    $user->warehouses()->attach($warehouseIds);

    return $user->fresh();
}

function tfWorld(): object
{
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
        'admin' => tfUser(Role::Admin),
        'manager' => tfUser(Role::Manager),
        'staffA' => tfUser(Role::WarehouseStaff, [$a->id]),
        'staffB' => tfUser(Role::WarehouseStaff, [$b->id]),
        'outsider' => tfUser(Role::WarehouseStaff),
    ];
}

function tfSeed(object $w, Warehouse $warehouse, Product $product, string $qty): void
{
    $receipt = GoodsReceipt::create(['warehouse_id' => $warehouse->id, 'created_by' => $w->admin->id]);
    $receipt->items()->create(['product_id' => $product->id, 'quantity' => $qty]);
    app(PostGoodsReceipt::class)->execute($receipt, $w->admin);
}

function tfDraft(object $w, array $lines, ?Warehouse $from = null, ?Warehouse $to = null): StockTransfer
{
    $transfer = StockTransfer::create([
        'source_warehouse_id' => ($from ?? $w->a)->id,
        'destination_warehouse_id' => ($to ?? $w->b)->id,
        'created_by' => $w->staffA->id,
    ]);

    foreach ($lines as [$product, $qty]) {
        $transfer->items()->create(['product_id' => $product->id, 'quantity' => $qty]);
    }

    return $transfer;
}

function tfApproved(object $w, array $lines): StockTransfer
{
    config(['stockpilot.transfer_requires_approval' => true]);

    $transfer = tfDraft($w, $lines);
    app(SubmitStockTransfer::class)->execute($transfer, $w->staffA);
    app(ApproveStockTransfer::class)->execute($transfer, $w->manager);

    return $transfer->fresh();
}

function tfDispatched(object $w, array $lines): StockTransfer
{
    $transfer = tfApproved($w, $lines);
    app(DispatchStockTransfer::class)->execute($transfer, $w->staffA);

    return $transfer->fresh();
}

function tfStock(Warehouse $warehouse, Product $product): float
{
    return (float) InventoryStock::where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->value('quantity');
}

function tfTransit(StockTransfer $transfer): float
{
    return (float) $transfer->items()->get()->sum(fn ($i) => (float) $i->remaining());
}

function tfReceive(StockTransfer $transfer, User $user, string $qty, ?string $key = null): StockTransfer
{
    $item = $transfer->items()->first();

    return app(ReceiveStockTransfer::class)->execute(
        $transfer,
        $user,
        [['item_id' => $item->id, 'quantity' => $qty]],
        $key ?? (string) \Illuminate\Support\Str::uuid(),
    );
}

it('does not touch stock while a transfer is a draft', function () {
    $w = tfWorld();
    tfSeed($w, $w->a, $w->p1, '10');
    tfDraft($w, [[$w->p1, '4']]);

    expect(tfStock($w->a, $w->p1))->toBe(10.0)
        ->and(tfStock($w->b, $w->p1))->toBe(0.0)
        ->and(StockMovement::where('movement_type', MovementType::TransferOut)->count())->toBe(0);
});

it('goes to pending approval or straight to approved depending on config', function () {
    $w = tfWorld();

    config(['stockpilot.transfer_requires_approval' => true]);
    $one = tfDraft($w, [[$w->p1, '1']]);
    app(SubmitStockTransfer::class)->execute($one, $w->staffA);
    expect($one->fresh()->status)->toBe(TransferStatus::PendingApproval);

    config(['stockpilot.transfer_requires_approval' => false]);
    $two = tfDraft($w, [[$w->p1, '1']]);
    app(SubmitStockTransfer::class)->execute($two, $w->staffA);
    expect($two->fresh()->status)->toBe(TransferStatus::Approved)
        ->and($two->fresh()->approved_by)->toBeNull();
});

it('lets only manager or admin approve, and only once', function () {
    $w = tfWorld();
    config(['stockpilot.transfer_requires_approval' => true]);
    $transfer = tfDraft($w, [[$w->p1, '1']]);
    app(SubmitStockTransfer::class)->execute($transfer, $w->staffA);
    $transfer = $transfer->fresh();

    expect($w->staffA->can('approve', $transfer))->toBeFalse()
        ->and($w->staffB->can('approve', $transfer))->toBeFalse()
        ->and($w->manager->can('approve', $transfer))->toBeTrue();

    app(ApproveStockTransfer::class)->execute($transfer, $w->manager);

    expect($transfer->fresh()->approved_by)->toBe($w->manager->id)
        ->and(fn () => app(ApproveStockTransfer::class)->execute($transfer, $w->manager))
        ->toThrow(InvalidTransferStateException::class);
});

it('takes stock from the source on dispatch and leaves the destination untouched', function () {
    $w = tfWorld();
    tfSeed($w, $w->a, $w->p1, '10');

    $transfer = tfDispatched($w, [[$w->p1, '8']]);

    expect($transfer->status)->toBe(TransferStatus::InTransit)
        ->and(tfStock($w->a, $w->p1))->toBe(2.0)
        ->and(tfStock($w->b, $w->p1))->toBe(0.0)
        ->and(tfTransit($transfer))->toBe(8.0)
        ->and(StockMovement::where('movement_type', MovementType::TransferOut)->count())->toBe(1)
        ->and($transfer->dispatched_by)->toBe($w->staffA->id);
});

it('rolls back every line when dispatch has insufficient stock', function () {
    $w = tfWorld();
    tfSeed($w, $w->a, $w->p1, '10');
    tfSeed($w, $w->a, $w->p2, '1');

    $transfer = tfApproved($w, [[$w->p1, '5'], [$w->p2, '3']]);

    expect(fn () => app(DispatchStockTransfer::class)->execute($transfer, $w->staffA))
        ->toThrow(InsufficientStockException::class);

    expect(tfStock($w->a, $w->p1))->toBe(10.0)
        ->and(tfStock($w->a, $w->p2))->toBe(1.0)
        ->and(StockMovement::where('movement_type', MovementType::TransferOut)->count())->toBe(0)
        ->and($transfer->fresh()->status)->toBe(TransferStatus::Approved)
        ->and(tfTransit($transfer))->toBe(0.0);
});

it('does not dispatch twice', function () {
    $w = tfWorld();
    tfSeed($w, $w->a, $w->p1, '10');
    $transfer = tfDispatched($w, [[$w->p1, '4']]);

    expect(fn () => app(DispatchStockTransfer::class)->execute($transfer, $w->staffA))
        ->toThrow(InvalidTransferStateException::class);

    expect(StockMovement::where('movement_type', MovementType::TransferOut)->count())->toBe(1)
        ->and(tfStock($w->a, $w->p1))->toBe(6.0);
});

it('supports partial receipts until everything is received', function () {
    $w = tfWorld();
    tfSeed($w, $w->a, $w->p1, '10');
    $transfer = tfDispatched($w, [[$w->p1, '8']]);

    tfReceive($transfer, $w->staffB, '5');

    expect($transfer->fresh()->status)->toBe(TransferStatus::PartiallyReceived)
        ->and(tfStock($w->b, $w->p1))->toBe(5.0)
        ->and(tfTransit($transfer))->toBe(3.0);

    tfReceive($transfer, $w->staffB, '3');

    expect($transfer->fresh()->status)->toBe(TransferStatus::Received)
        ->and($transfer->fresh()->completed_at)->not->toBeNull()
        ->and(tfStock($w->b, $w->p1))->toBe(8.0)
        ->and(tfTransit($transfer))->toBe(0.0);
});

it('rejects receiving more than what is still in transit', function () {
    $w = tfWorld();
    tfSeed($w, $w->a, $w->p1, '10');
    $transfer = tfDispatched($w, [[$w->p1, '8']]);

    expect(fn () => tfReceive($transfer, $w->staffB, '9'))->toThrow(DomainException::class)
        ->and(fn () => tfReceive($transfer, $w->staffB, '0'))->toThrow(DomainException::class)
        ->and(fn () => tfReceive($transfer, $w->staffB, '-1'))->toThrow(DomainException::class);

    expect(tfStock($w->b, $w->p1))->toBe(0.0)
        ->and(StockMovement::where('movement_type', MovementType::TransferIn)->count())->toBe(0)
        ->and($transfer->fresh()->status)->toBe(TransferStatus::InTransit);
});

it('ignores a repeated receipt with the same idempotency key', function () {
    $w = tfWorld();
    tfSeed($w, $w->a, $w->p1, '10');
    $transfer = tfDispatched($w, [[$w->p1, '8']]);

    tfReceive($transfer, $w->staffB, '5', 'key-1');

    expect(fn () => tfReceive($transfer, $w->staffB, '5', 'key-1'))->toThrow(DomainException::class);

    expect(tfStock($w->b, $w->p1))->toBe(5.0)
        ->and(StockMovement::where('movement_type', MovementType::TransferIn)->count())->toBe(1);
});

it('returns the remainder to the source and closes as received when something arrived', function () {
    $w = tfWorld();
    tfSeed($w, $w->a, $w->p1, '10');
    $transfer = tfDispatched($w, [[$w->p1, '8']]);
    tfReceive($transfer, $w->staffB, '5');

    app(ReturnTransferRemainder::class)->execute($transfer, $w->staffA);

    expect($transfer->fresh()->status)->toBe(TransferStatus::Received)
        ->and(tfStock($w->a, $w->p1))->toBe(5.0)
        ->and(tfStock($w->b, $w->p1))->toBe(5.0)
        ->and(tfTransit($transfer))->toBe(0.0)
        ->and(StockMovement::where('movement_type', MovementType::TransferReturn)->count())->toBe(1);

    expect(fn () => app(ReturnTransferRemainder::class)->execute($transfer, $w->staffA))
        ->toThrow(InvalidTransferStateException::class);
});

it('marks a transfer cancelled when everything is returned without any receipt', function () {
    $w = tfWorld();
    tfSeed($w, $w->a, $w->p1, '10');
    $transfer = tfDispatched($w, [[$w->p1, '8']]);

    app(ReturnTransferRemainder::class)->execute($transfer, $w->staffA);

    expect($transfer->fresh()->status)->toBe(TransferStatus::Cancelled)
        ->and(tfStock($w->a, $w->p1))->toBe(10.0)
        ->and(tfStock($w->b, $w->p1))->toBe(0.0);
});

it('never double counts stock: balances plus transit stay constant and match the ledger', function () {
    $w = tfWorld();
    tfSeed($w, $w->a, $w->p1, '10');
    $total = fn (StockTransfer $t) => tfStock($w->a, $w->p1) + tfStock($w->b, $w->p1) + tfTransit($t);

    $transfer = tfApproved($w, [[$w->p1, '8']]);
    expect($total($transfer))->toBe(10.0);

    app(DispatchStockTransfer::class)->execute($transfer, $w->staffA);
    expect($total($transfer))->toBe(10.0);

    tfReceive($transfer, $w->staffB, '5');
    expect($total($transfer))->toBe(10.0);

    app(ReturnTransferRemainder::class)->execute($transfer, $w->staffA);
    expect($total($transfer))->toBe(10.0);

    foreach ([$w->a, $w->b] as $warehouse) {
        $ledger = (float) StockMovement::where('warehouse_id', $warehouse->id)
            ->where('product_id', $w->p1->id)
            ->sum('quantity_delta');

        expect(tfStock($warehouse, $w->p1))->toBe($ledger);
    }
});

it('cancels only before dispatch', function () {
    $w = tfWorld();
    tfSeed($w, $w->a, $w->p1, '10');

    $approved = tfApproved($w, [[$w->p1, '3']]);
    app(CancelStockTransfer::class)->execute($approved, $w->manager);

    expect($approved->fresh()->status)->toBe(TransferStatus::Cancelled)
        ->and(StockMovement::where('movement_type', MovementType::TransferOut)->count())->toBe(0);

    $inTransit = tfDispatched($w, [[$w->p1, '3']]);

    expect(fn () => app(CancelStockTransfer::class)->execute($inTransit, $w->manager))
        ->toThrow(InvalidTransferStateException::class)
        ->and($inTransit->fresh()->status)->toBe(TransferStatus::InTransit);
});

it('splits responsibilities by warehouse in the policy', function () {
    $w = tfWorld();
    tfSeed($w, $w->a, $w->p1, '10');

    $approved = tfApproved($w, [[$w->p1, '5']]);

    expect($w->staffA->can('dispatch', $approved))->toBeTrue()
        ->and($w->staffB->can('dispatch', $approved))->toBeFalse()
        ->and($w->manager->can('dispatch', $approved))->toBeFalse()
        ->and($w->outsider->can('view', $approved))->toBeFalse()
        ->and($w->staffB->can('view', $approved))->toBeTrue();

    app(DispatchStockTransfer::class)->execute($approved, $w->staffA);
    $inTransit = $approved->fresh();

    expect($w->staffB->can('receive', $inTransit))->toBeTrue()
        ->and($w->staffA->can('receive', $inTransit))->toBeFalse()
        ->and($w->outsider->can('receive', $inTransit))->toBeFalse()
        ->and($w->staffA->can('returnToSource', $inTransit))->toBeTrue()
        ->and($w->staffB->can('returnToSource', $inTransit))->toBeFalse()
        ->and($w->staffA->can('cancel', $inTransit))->toBeFalse();
});

it('rejects submitting a transfer with the same source and destination', function () {
    $w = tfWorld();
    $transfer = tfDraft($w, [[$w->p1, '1']], $w->a, $w->a);

    expect(fn () => app(SubmitStockTransfer::class)->execute($transfer, $w->staffA))
        ->toThrow(DomainException::class);

    expect($transfer->fresh()->status)->toBe(TransferStatus::Draft);
});

it('locks planned items and warehouses once a transfer leaves draft', function () {
    $w = tfWorld();
    $transfer = tfApproved($w, [[$w->p1, '5']])->fresh();

    expect(fn () => $transfer->items()->first()->update(['quantity' => 99]))->toThrow(LogicException::class)
        ->and(fn () => $transfer->items()->first()->delete())->toThrow(LogicException::class)
        ->and(fn () => $transfer->update(['destination_warehouse_id' => $w->a->id]))->toThrow(LogicException::class)
        ->and(fn () => $transfer->delete())->toThrow(LogicException::class);
});
