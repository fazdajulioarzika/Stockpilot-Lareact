<?php

use App\Actions\Inventory\DispatchStockTransfer;
use App\Actions\Inventory\PostGoodsReceipt;
use App\Actions\Inventory\PostStockIssue;
use App\Actions\Inventory\RecordStocktakeCount;
use App\Actions\Inventory\RejectStocktake;
use App\Actions\Inventory\StartStocktake;
use App\Actions\Inventory\SubmitStockTransfer;
use App\Actions\Inventory\SubmitStocktakeForApproval;
use App\Enums\Role;
use App\Exceptions\InsufficientStockException;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\StockIssue;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function auUser(Role $role): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    return $user->fresh();
}

function auWorld(): object
{
    config(['stockpilot.transfer_requires_approval' => false]);

    $category = Category::create(['name' => 'Cat', 'slug' => 'cat']);
    $unit = Unit::create(['name' => 'Pieces', 'symbol' => 'pcs']);

    return (object) [
        'a' => Warehouse::create(['code' => 'GD-A', 'name' => 'A']),
        'b' => Warehouse::create(['code' => 'GD-B', 'name' => 'B']),
        'p1' => Product::create(['category_id' => $category->id, 'unit_id' => $unit->id, 'sku' => 'SKU-1', 'name' => 'SKU-1']),
        'admin' => auUser(Role::Admin),
        'manager' => auUser(Role::Manager),
        'staff' => auUser(Role::WarehouseStaff),
    ];
}

function auSeed(object $w, string $qty): void
{
    $receipt = GoodsReceipt::create(['warehouse_id' => $w->a->id, 'created_by' => $w->admin->id]);
    $receipt->items()->create(['product_id' => $w->p1->id, 'quantity' => $qty]);
    app(PostGoodsReceipt::class)->execute($receipt, $w->admin);
}

function auActions(): array
{
    return AuditLog::orderBy('id')->pluck('action')->all();
}

it('records who posted a document and what it was', function () {
    $w = auWorld();
    auSeed($w, '10');

    $log = AuditLog::first();

    expect(AuditLog::count())->toBe(1)
        ->and($log->action)->toBe('goods_receipt.posted')
        ->and($log->user_id)->toBe($w->admin->id)
        ->and($log->entity_type)->toBe(GoodsReceipt::class)
        ->and($log->entity_id)->toBe(GoodsReceipt::first()->id)
        ->and($log->metadata['items'])->toBe(1)
        ->and($log->created_at)->not->toBeNull();
});

it('writes no audit entry when the action rolls back', function () {
    $w = auWorld();

    $issue = StockIssue::create(['warehouse_id' => $w->a->id, 'reason' => 'Penjualan', 'created_by' => $w->admin->id]);
    $issue->items()->create(['product_id' => $w->p1->id, 'quantity' => '5']);

    expect(fn () => app(PostStockIssue::class)->execute($issue, $w->admin))
        ->toThrow(InsufficientStockException::class);

    expect(AuditLog::count())->toBe(0);
});

it('records the transfer lifecycle in order', function () {
    $w = auWorld();
    auSeed($w, '10');

    $transfer = StockTransfer::create([
        'source_warehouse_id' => $w->a->id,
        'destination_warehouse_id' => $w->b->id,
        'created_by' => $w->admin->id,
    ]);
    $transfer->items()->create(['product_id' => $w->p1->id, 'quantity' => '4']);

    app(SubmitStockTransfer::class)->execute($transfer, $w->admin);
    app(DispatchStockTransfer::class)->execute($transfer, $w->admin);

    expect(auActions())->toBe(['goods_receipt.posted', 'transfer.submitted', 'transfer.dispatched']);
});

it('records the stocktake lifecycle including the rejection note', function () {
    $w = auWorld();
    auSeed($w, '10');

    $stocktake = Stocktake::create(['warehouse_id' => $w->a->id, 'created_by' => $w->admin->id]);
    app(StartStocktake::class)->execute($stocktake, $w->admin);

    $item = $stocktake->items()->first();
    app(RecordStocktakeCount::class)->execute($stocktake, $w->admin, [
        ['item_id' => $item->id, 'counted_qty' => '8', 'reason' => 'rusak'],
    ]);
    app(SubmitStocktakeForApproval::class)->execute($stocktake, $w->admin);
    app(RejectStocktake::class)->execute($stocktake, $w->manager, 'Hitung ulang rak 3');

    expect(array_slice(auActions(), 1))->toBe(['stocktake.started', 'stocktake.submitted', 'stocktake.rejected']);

    $rejected = AuditLog::where('action', 'stocktake.rejected')->first();

    expect($rejected->user_id)->toBe($w->manager->id)
        ->and($rejected->metadata['note'])->toBe('Hitung ulang rak 3');
});

it('keeps audit entries immutable and strips secret keys', function () {
    $w = auWorld();

    $log = AuditLog::record('user.updated', $w->staff, $w->admin, ['password' => 'rahasia', 'role' => 'manager']);

    expect($log->metadata)->toBe(['role' => 'manager'])
        ->and(fn () => $log->update(['action' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class);
});

it('lets only admin and manager open the audit log', function () {
    $w = auWorld();

    $this->actingAs($w->admin)->get('/audit-logs')->assertInertia(fn (Assert $page) => $page
        ->component('audit-logs/index'));
    $this->actingAs($w->manager)->get('/audit-logs')->assertOk();
    $this->actingAs($w->staff)->get('/audit-logs')->assertForbidden();

    auth()->logout();
    $this->get('/audit-logs')->assertRedirect('/login');
});

it('filters the audit log by action and user', function () {
    $w = auWorld();
    AuditLog::record('goods_receipt.posted', $w->staff, $w->admin);
    AuditLog::record('transfer.approved', $w->staff, $w->manager);

    $this->actingAs($w->admin)->get('/audit-logs?action=transfer.approved')
        ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('logs.data.0.user', $w->manager->name));

    $this->actingAs($w->admin)->get("/audit-logs?user_id={$w->admin->id}")
        ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('logs.data.0.action', 'goods_receipt.posted'));

    $this->actingAs($w->admin)->get('/audit-logs')
        ->assertInertia(fn (Assert $page) => $page->has('logs.data', 2)->has('actions', 2));
});
