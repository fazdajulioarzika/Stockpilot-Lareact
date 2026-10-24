<?php

use App\Actions\Inventory\CancelStocktake;
use App\Actions\Inventory\DispatchStockTransfer;
use App\Actions\Inventory\PostGoodsReceipt;
use App\Actions\Inventory\PostStockIssue;
use App\Actions\Inventory\PostStocktake;
use App\Actions\Inventory\ReceiveStockTransfer;
use App\Actions\Inventory\RecordStocktakeCount;
use App\Actions\Inventory\RejectStocktake;
use App\Actions\Inventory\StartStocktake;
use App\Actions\Inventory\SubmitStockTransfer;
use App\Actions\Inventory\SubmitStocktakeForApproval;
use App\Enums\DocumentStatus;
use App\Enums\MovementType;
use App\Enums\Role;
use App\Enums\StocktakeStatus;
use App\Enums\TransferStatus;
use App\Exceptions\InvalidStocktakeStateException;
use App\Exceptions\WarehouseLockedException;
use App\Models\Category;
use App\Models\GoodsReceipt;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\StockIssue;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Stocktake;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function soUser(Role $role, array $warehouseIds = []): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();
    $user->warehouses()->attach($warehouseIds);

    return $user->fresh();
}

function soWorld(): object
{
    config(['stockpilot.transfer_requires_approval' => false]);

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
        'p3' => $make('SKU-3'),
        'admin' => soUser(Role::Admin),
        'manager' => soUser(Role::Manager),
        'staffA' => soUser(Role::WarehouseStaff, [$a->id]),
        'staffB' => soUser(Role::WarehouseStaff, [$b->id]),
        'outsider' => soUser(Role::WarehouseStaff),
    ];
}

function soReceipt(object $w, Warehouse $warehouse, Product $product, string $qty): GoodsReceipt
{
    $receipt = GoodsReceipt::create(['warehouse_id' => $warehouse->id, 'created_by' => $w->admin->id]);
    $receipt->items()->create(['product_id' => $product->id, 'quantity' => $qty]);

    return $receipt;
}

function soIssue(object $w, Warehouse $warehouse, Product $product, string $qty): StockIssue
{
    $issue = StockIssue::create([
        'warehouse_id' => $warehouse->id,
        'reason' => 'Penjualan',
        'created_by' => $w->admin->id,
    ]);
    $issue->items()->create(['product_id' => $product->id, 'quantity' => $qty]);

    return $issue;
}

function soSeed(object $w, Warehouse $warehouse, Product $product, string $qty): void
{
    app(PostGoodsReceipt::class)->execute(soReceipt($w, $warehouse, $product, $qty), $w->admin);
}

function soTransfer(object $w, Warehouse $from, Warehouse $to, Product $product, string $qty): StockTransfer
{
    $transfer = StockTransfer::create([
        'source_warehouse_id' => $from->id,
        'destination_warehouse_id' => $to->id,
        'created_by' => $w->admin->id,
    ]);
    $transfer->items()->create(['product_id' => $product->id, 'quantity' => $qty]);

    // Approval dimatikan di soWorld(), jadi langsung berstatus approved.
    app(SubmitStockTransfer::class)->execute($transfer, $w->admin);

    return $transfer->fresh();
}

function soDraft(object $w, ?Warehouse $warehouse = null, array $extra = []): Stocktake
{
    $stocktake = Stocktake::create([
        'warehouse_id' => ($warehouse ?? $w->a)->id,
        'created_by' => $w->staffA->id,
    ]);

    foreach ($extra as $product) {
        $stocktake->items()->create(['product_id' => $product->id]);
    }

    return $stocktake;
}

function soStart(object $w, ?Warehouse $warehouse = null, array $extra = []): Stocktake
{
    $stocktake = soDraft($w, $warehouse, $extra);
    app(StartStocktake::class)->execute($stocktake, $w->staffA);

    return $stocktake->fresh();
}

/** @param array<int, string|array{0: string|null, 1: string|null}> $counts product_id => qty atau [qty, alasan] */
function soCount(Stocktake $stocktake, User $user, array $counts): void
{
    $items = $stocktake->items()->get()->keyBy('product_id');
    $lines = [];

    foreach ($counts as $productId => $spec) {
        [$qty, $reason] = is_array($spec) ? $spec : [$spec, null];
        $lines[] = ['item_id' => $items[$productId]->id, 'counted_qty' => $qty, 'reason' => $reason];
    }

    app(RecordStocktakeCount::class)->execute($stocktake, $user, $lines);
}

function soBalance(Warehouse $warehouse, Product $product): float
{
    return (float) InventoryStock::where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->value('quantity');
}

it('does not lock the warehouse while the stocktake is still a draft', function () {
    $w = soWorld();
    soSeed($w, $w->a, $w->p1, '10');
    soDraft($w);

    app(PostGoodsReceipt::class)->execute(soReceipt($w, $w->a, $w->p1, '5'), $w->admin);

    expect(soBalance($w->a, $w->p1))->toBe(15.0);
});

it('snapshots balances and includes extra draft products when counting starts', function () {
    $w = soWorld();
    soSeed($w, $w->a, $w->p1, '10');
    soSeed($w, $w->a, $w->p2, '4');

    $stocktake = soStart($w, null, [$w->p3]); // p3 tidak punya stok
    $system = $stocktake->items()->get()->mapWithKeys(fn ($i) => [$i->product_id => (float) $i->system_qty]);

    expect($stocktake->status)->toBe(StocktakeStatus::Counting)
        ->and($stocktake->number)->toStartWith('SO-')
        ->and($stocktake->started_by)->toBe($w->staffA->id)
        ->and($system->all())->toBe([$w->p1->id => 10.0, $w->p2->id => 4.0, $w->p3->id => 0.0]);
});

it('refuses to start a stocktake with nothing to count', function () {
    $w = soWorld();
    $draft = soDraft($w);

    expect(fn () => app(StartStocktake::class)->execute($draft, $w->staffA))->toThrow(DomainException::class);

    expect($draft->fresh()->status)->toBe(StocktakeStatus::Draft);
});

it('allows only one active stocktake per warehouse', function () {
    $w = soWorld();
    soSeed($w, $w->a, $w->p1, '10');
    soSeed($w, $w->b, $w->p1, '10');

    $one = soStart($w);
    $two = soDraft($w);

    expect(fn () => app(StartStocktake::class)->execute($two, $w->staffA))->toThrow(DomainException::class);
    expect($two->fresh()->status)->toBe(StocktakeStatus::Draft);

    // Gudang lain tidak terpengaruh.
    expect(soStart($w, $w->b)->status)->toBe(StocktakeStatus::Counting);

    // Setelah dibatalkan, opname berikutnya boleh mulai.
    app(CancelStocktake::class)->execute($one, $w->admin);
    app(StartStocktake::class)->execute($two, $w->staffA);

    expect($two->fresh()->status)->toBe(StocktakeStatus::Counting);
});

it('blocks receipts and issues in a warehouse that is being counted, but not in others', function () {
    $w = soWorld();
    soSeed($w, $w->a, $w->p1, '10');
    soStart($w);

    $receipt = soReceipt($w, $w->a, $w->p1, '5');
    $issue = soIssue($w, $w->a, $w->p1, '2');

    expect(fn () => app(PostGoodsReceipt::class)->execute($receipt, $w->admin))->toThrow(WarehouseLockedException::class)
        ->and(fn () => app(PostStockIssue::class)->execute($issue, $w->admin))->toThrow(WarehouseLockedException::class);

    expect(soBalance($w->a, $w->p1))->toBe(10.0)
        ->and(StockMovement::count())->toBe(1) // hanya penerimaan awal
        ->and($receipt->fresh()->status)->toBe(DocumentStatus::Draft)
        ->and($issue->fresh()->status)->toBe(DocumentStatus::Draft);

    // Gudang B tidak terkunci.
    app(PostGoodsReceipt::class)->execute(soReceipt($w, $w->b, $w->p1, '3'), $w->admin);

    expect(soBalance($w->b, $w->p1))->toBe(3.0);
});

it('blocks transfer dispatch at the source and receipt at the destination', function () {
    $w = soWorld();
    soSeed($w, $w->a, $w->p1, '10');
    soSeed($w, $w->b, $w->p2, '1');

    $transfer = soTransfer($w, $w->a, $w->b, $w->p1, '4');
    expect($transfer->status)->toBe(TransferStatus::Approved);

    $countingA = soStart($w, $w->a);

    expect(fn () => app(DispatchStockTransfer::class)->execute($transfer, $w->admin))
        ->toThrow(WarehouseLockedException::class);

    expect($transfer->fresh()->status)->toBe(TransferStatus::Approved)
        ->and(soBalance($w->a, $w->p1))->toBe(10.0);

    app(CancelStocktake::class)->execute($countingA, $w->admin);
    app(DispatchStockTransfer::class)->execute($transfer, $w->admin);

    expect($transfer->fresh()->status)->toBe(TransferStatus::InTransit)
        ->and(soBalance($w->a, $w->p1))->toBe(6.0);

    $countingB = soStart($w, $w->b);
    $item = $transfer->items()->first();
    $receive = fn () => app(ReceiveStockTransfer::class)->execute(
        $transfer,
        $w->admin,
        [['item_id' => $item->id, 'quantity' => '4']],
        'key-1',
    );

    expect($receive)->toThrow(WarehouseLockedException::class);
    expect(soBalance($w->b, $w->p1))->toBe(0.0)
        ->and($transfer->fresh()->status)->toBe(TransferStatus::InTransit);

    app(CancelStocktake::class)->execute($countingB, $w->admin);
    $receive();

    expect(soBalance($w->b, $w->p1))->toBe(4.0)
        ->and($transfer->fresh()->status)->toBe(TransferStatus::Received);
});

it('computes differences, allows clearing a count and rejects invalid quantities', function () {
    $w = soWorld();
    soSeed($w, $w->a, $w->p1, '10');
    $stocktake = soStart($w);
    $item = fn () => $stocktake->items()->first();

    soCount($stocktake, $w->staffA, [$w->p1->id => ['7', 'rusak']]);

    expect((float) $item()->counted_qty)->toBe(7.0)
        ->and((float) $item()->difference)->toBe(-3.0)
        ->and($item()->reason)->toBe('rusak');

    soCount($stocktake, $w->staffA, [$w->p1->id => [null, null]]);

    expect($item()->counted_qty)->toBeNull()->and($item()->difference)->toBeNull();

    foreach (['-1', 'abc', '1e3', '1.2345'] as $bad) {
        expect(fn () => soCount($stocktake, $w->staffA, [$w->p1->id => $bad]))->toThrow(DomainException::class);
    }

    $draft = soDraft($w, $w->b);

    expect(fn () => app(RecordStocktakeCount::class)->execute($draft, $w->staffA, []))
        ->toThrow(InvalidStocktakeStateException::class);
});

it('requires every item counted and a reason for every difference before submitting', function () {
    $w = soWorld();
    soSeed($w, $w->a, $w->p1, '10');
    soSeed($w, $w->a, $w->p2, '4');
    $stocktake = soStart($w);
    $submit = fn () => app(SubmitStocktakeForApproval::class)->execute($stocktake, $w->staffA);

    expect($submit)->toThrow(DomainException::class); // belum ada yang dihitung

    soCount($stocktake, $w->staffA, [$w->p1->id => '8', $w->p2->id => '4']);

    expect($submit)->toThrow(DomainException::class); // p1 selisih tanpa alasan

    soCount($stocktake, $w->staffA, [$w->p1->id => ['8', 'rusak']]);
    $submit();

    $fresh = $stocktake->fresh();

    expect($fresh->status)->toBe(StocktakeStatus::PendingApproval)
        ->and($fresh->submitted_by)->toBe($w->staffA->id);
});

it('keeps the warehouse locked while pending approval, and a rejection returns to counting', function () {
    $w = soWorld();
    soSeed($w, $w->a, $w->p1, '10');
    $stocktake = soStart($w);
    soCount($stocktake, $w->staffA, [$w->p1->id => ['8', 'rusak']]);
    app(SubmitStocktakeForApproval::class)->execute($stocktake, $w->staffA);

    $receipt = soReceipt($w, $w->a, $w->p1, '1');

    expect(fn () => app(PostGoodsReceipt::class)->execute($receipt, $w->admin))
        ->toThrow(WarehouseLockedException::class);

    expect(fn () => app(RejectStocktake::class)->execute($stocktake, $w->manager, '  '))
        ->toThrow(DomainException::class);

    app(RejectStocktake::class)->execute($stocktake, $w->manager, 'Hitung ulang rak 3');

    $fresh = $stocktake->fresh();

    expect($fresh->status)->toBe(StocktakeStatus::Counting)
        ->and($fresh->rejection_note)->toBe('Hitung ulang rak 3')
        ->and($fresh->submitted_by)->toBeNull()
        ->and((float) $fresh->items()->first()->counted_qty)->toBe(8.0); // hasil hitung tidak hilang

    expect(fn () => app(PostGoodsReceipt::class)->execute($receipt, $w->admin))
        ->toThrow(WarehouseLockedException::class);
});

it('posts adjustments for shortage and surplus and leaves matching items alone', function () {
    $w = soWorld();
    soSeed($w, $w->a, $w->p1, '10');
    soSeed($w, $w->a, $w->p2, '4');
    soSeed($w, $w->a, $w->p3, '5');

    $stocktake = soStart($w);
    soCount($stocktake, $w->staffA, [
        $w->p1->id => ['8', 'rusak'],
        $w->p2->id => ['6', 'ditemukan di rak lain'],
        $w->p3->id => '5',
    ]);
    app(SubmitStocktakeForApproval::class)->execute($stocktake, $w->staffA);

    app(PostStocktake::class)->execute($stocktake, $w->manager);

    $adjustments = StockMovement::where('movement_type', MovementType::Adjustment);
    $delta = fn (Product $p) => (float) StockMovement::where('movement_type', MovementType::Adjustment)
        ->where('product_id', $p->id)->value('quantity_delta');

    expect(soBalance($w->a, $w->p1))->toBe(8.0)
        ->and(soBalance($w->a, $w->p2))->toBe(6.0)
        ->and(soBalance($w->a, $w->p3))->toBe(5.0)
        ->and($adjustments->count())->toBe(2)
        ->and($delta($w->p1))->toBe(-2.0)
        ->and($delta($w->p2))->toBe(2.0)
        ->and($stocktake->fresh()->status)->toBe(StocktakeStatus::Posted)
        ->and($stocktake->fresh()->approved_by)->toBe($w->manager->id);

    foreach ([$w->p1, $w->p2, $w->p3] as $product) {
        $ledger = (float) StockMovement::where('warehouse_id', $w->a->id)
            ->where('product_id', $product->id)
            ->sum('quantity_delta');

        expect(soBalance($w->a, $product))->toBe($ledger);
    }
});

it('creates a balance row when an extra product with no system stock is found', function () {
    $w = soWorld();
    soSeed($w, $w->a, $w->p1, '10');

    $stocktake = soStart($w, null, [$w->p2]);
    soCount($stocktake, $w->staffA, [
        $w->p1->id => '10',
        $w->p2->id => ['3', 'ditemukan, belum tercatat'],
    ]);
    app(SubmitStocktakeForApproval::class)->execute($stocktake, $w->staffA);
    app(PostStocktake::class)->execute($stocktake, $w->manager);

    expect(soBalance($w->a, $w->p2))->toBe(3.0)
        ->and(StockMovement::where('movement_type', MovementType::Adjustment)->count())->toBe(1);
});

it('does not post twice', function () {
    $w = soWorld();
    soSeed($w, $w->a, $w->p1, '10');
    $stocktake = soStart($w);
    soCount($stocktake, $w->staffA, [$w->p1->id => ['8', 'rusak']]);
    app(SubmitStocktakeForApproval::class)->execute($stocktake, $w->staffA);
    app(PostStocktake::class)->execute($stocktake, $w->manager);

    expect(fn () => app(PostStocktake::class)->execute($stocktake, $w->manager))
        ->toThrow(InvalidStocktakeStateException::class);

    expect(StockMovement::where('movement_type', MovementType::Adjustment)->count())->toBe(1)
        ->and(soBalance($w->a, $w->p1))->toBe(8.0);
});

it('refuses to post when the balance no longer matches the snapshot', function () {
    $w = soWorld();
    soSeed($w, $w->a, $w->p1, '10');
    $stocktake = soStart($w);
    soCount($stocktake, $w->staffA, [$w->p1->id => ['8', 'rusak']]);
    app(SubmitStocktakeForApproval::class)->execute($stocktake, $w->staffA);

    // Simulasi data diubah di luar aplikasi.
    DB::table('inventory_stocks')->where('warehouse_id', $w->a->id)->update(['quantity' => 7]);

    expect(fn () => app(PostStocktake::class)->execute($stocktake, $w->manager))->toThrow(DomainException::class);

    expect($stocktake->fresh()->status)->toBe(StocktakeStatus::PendingApproval)
        ->and(StockMovement::where('movement_type', MovementType::Adjustment)->count())->toBe(0)
        ->and(soBalance($w->a, $w->p1))->toBe(7.0);
});

it('releases the lock after posting and after cancelling', function () {
    $w = soWorld();
    soSeed($w, $w->a, $w->p1, '10');

    $cancelled = soStart($w);
    app(CancelStocktake::class)->execute($cancelled, $w->staffA);
    app(PostGoodsReceipt::class)->execute(soReceipt($w, $w->a, $w->p1, '1'), $w->admin);

    expect($cancelled->fresh()->status)->toBe(StocktakeStatus::Cancelled)
        ->and(soBalance($w->a, $w->p1))->toBe(11.0);

    $posted = soStart($w);
    soCount($posted, $w->staffA, [$w->p1->id => ['11', null]]);
    app(SubmitStocktakeForApproval::class)->execute($posted, $w->staffA);
    app(PostStocktake::class)->execute($posted, $w->manager);
    app(PostGoodsReceipt::class)->execute(soReceipt($w, $w->a, $w->p1, '2'), $w->admin);

    expect(soBalance($w->a, $w->p1))->toBe(13.0);

    expect(fn () => app(CancelStocktake::class)->execute($posted, $w->admin))
        ->toThrow(InvalidStocktakeStateException::class);
});

it('splits responsibilities in the policy', function () {
    $w = soWorld();
    soSeed($w, $w->a, $w->p1, '10');
    $stocktake = soStart($w);

    expect($w->staffA->can('count', $stocktake))->toBeTrue()
        ->and($w->staffB->can('count', $stocktake))->toBeFalse()
        ->and($w->manager->can('count', $stocktake))->toBeFalse()
        ->and($w->staffA->can('view', $stocktake))->toBeTrue()
        ->and($w->staffB->can('view', $stocktake))->toBeFalse()
        ->and($w->outsider->can('view', $stocktake))->toBeFalse()
        ->and($w->manager->can('view', $stocktake))->toBeTrue();

    soCount($stocktake, $w->admin, [$w->p1->id => ['8', 'rusak']]);
    app(SubmitStocktakeForApproval::class)->execute($stocktake, $w->admin); // diajukan Admin
    $pending = $stocktake->fresh();

    expect($w->manager->can('approve', $pending))->toBeTrue()
        ->and($w->admin->can('approve', $pending))->toBeFalse() // tidak boleh menyetujui hasil sendiri
        ->and($w->staffA->can('approve', $pending))->toBeFalse()
        ->and($w->manager->can('reject', $pending))->toBeTrue()
        ->and($w->staffA->can('reject', $pending))->toBeFalse()
        ->and($w->staffA->can('cancel', $pending))->toBeTrue()
        ->and($w->outsider->can('cancel', $pending))->toBeFalse();
});

it('locks a posted stocktake and its items against edits', function () {
    $w = soWorld();
    soSeed($w, $w->a, $w->p1, '10');
    $stocktake = soStart($w);
    soCount($stocktake, $w->staffA, [$w->p1->id => ['8', 'rusak']]);
    app(SubmitStocktakeForApproval::class)->execute($stocktake, $w->staffA);
    app(PostStocktake::class)->execute($stocktake, $w->manager);

    $posted = $stocktake->fresh();

    expect(fn () => $posted->update(['notes' => 'ubah']))->toThrow(LogicException::class)
        ->and(fn () => $posted->delete())->toThrow(LogicException::class)
        ->and(fn () => $posted->items()->first()->forceFill(['counted_qty' => 1])->save())->toThrow(LogicException::class)
        ->and(fn () => $posted->items()->first()->delete())->toThrow(LogicException::class);
});
