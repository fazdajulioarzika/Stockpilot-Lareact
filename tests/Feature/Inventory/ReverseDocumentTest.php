<?php

use App\Actions\Inventory\PostGoodsReceipt;
use App\Actions\Inventory\PostStockIssue;
use App\Actions\Inventory\ReverseStockDocument;
use App\Actions\Inventory\StartStocktake;
use App\Enums\DocumentStatus;
use App\Enums\MovementType;
use App\Enums\Role;
use App\Exceptions\WarehouseLockedException;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\DocumentReversal;
use App\Models\GoodsReceipt;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\StockIssue;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function rvUser(Role $role, array $warehouseIds = []): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();
    $user->warehouses()->attach($warehouseIds);

    return $user->fresh();
}

function rvWorld(): object
{
    $category = Category::create(['name' => 'Cat', 'slug' => 'cat']);
    $unit = Unit::create(['name' => 'Pieces', 'symbol' => 'pcs']);
    $a = Warehouse::create(['code' => 'GD-A', 'name' => 'A']);

    return (object) [
        'a' => $a,
        'p1' => Product::create(['category_id' => $category->id, 'unit_id' => $unit->id, 'sku' => 'SKU-1', 'name' => 'SKU-1']),
        'admin' => rvUser(Role::Admin),
        'manager' => rvUser(Role::Manager),
        'staff' => rvUser(Role::WarehouseStaff, [$a->id]),
    ];
}

function rvDraftReceipt(object $w, string $qty): GoodsReceipt
{
    $receipt = GoodsReceipt::create(['warehouse_id' => $w->a->id, 'created_by' => $w->admin->id]);
    $receipt->items()->create(['product_id' => $w->p1->id, 'quantity' => $qty]);

    return $receipt;
}

function rvReceipt(object $w, string $qty): GoodsReceipt
{
    $receipt = rvDraftReceipt($w, $qty);
    app(PostGoodsReceipt::class)->execute($receipt, $w->admin);

    return $receipt->fresh();
}

function rvIssue(object $w, string $qty): StockIssue
{
    $issue = StockIssue::create(['warehouse_id' => $w->a->id, 'reason' => 'Penjualan', 'created_by' => $w->admin->id]);
    $issue->items()->create(['product_id' => $w->p1->id, 'quantity' => $qty]);
    app(PostStockIssue::class)->execute($issue, $w->admin);

    return $issue->fresh();
}

function rvBalance(object $w): float
{
    return (float) InventoryStock::where('warehouse_id', $w->a->id)
        ->where('product_id', $w->p1->id)
        ->value('quantity');
}

it('reverses a posted receipt, keeps the document intact and writes ledger and audit entries', function () {
    $w = rvWorld();
    $receipt = rvReceipt($w, '10');

    app(ReverseStockDocument::class)->execute($receipt, $w->manager, 'Salah input');

    $movement = StockMovement::where('movement_type', MovementType::Reversal->value)->first();
    $log = AuditLog::where('action', 'goods_receipt.reversed')->first();

    expect(rvBalance($w))->toBe(0.0)
        ->and($receipt->fresh()->status)->toBe(DocumentStatus::Posted)
        ->and($receipt->fresh()->reversal)->not->toBeNull()
        ->and($receipt->fresh()->reversal->reversed_by)->toBe($w->manager->id)
        ->and($movement->quantity_delta)->toBe('-10.000')
        ->and($movement->reference_type)->toBe(GoodsReceipt::class)
        ->and($movement->reference_id)->toBe($receipt->id)
        ->and($movement->created_by)->toBe($w->manager->id)
        ->and($log->metadata['reason'])->toBe('Salah input')
        ->and(fn () => $receipt->fresh()->update(['notes' => 'ubah']))->toThrow(LogicException::class);
});

it('reverses a posted issue and gives the stock back', function () {
    $w = rvWorld();
    rvReceipt($w, '10');
    $issue = rvIssue($w, '3');

    expect(rvBalance($w))->toBe(7.0);

    app(ReverseStockDocument::class)->execute($issue, $w->admin, 'Salah pelanggan');

    expect(rvBalance($w))->toBe(10.0)
        ->and(StockMovement::where('movement_type', MovementType::Reversal->value)->first()->quantity_delta)->toBe('3.000')
        ->and(AuditLog::where('action', 'stock_issue.reversed')->exists())->toBeTrue();
});

it('reverses only once', function () {
    $w = rvWorld();
    $receipt = rvReceipt($w, '10');
    $action = app(ReverseStockDocument::class);

    $action->execute($receipt, $w->manager, 'Salah input');

    expect(fn () => $action->execute($receipt, $w->manager, 'Lagi'))->toThrow(DomainException::class, 'sudah dibalik');

    expect(StockMovement::where('movement_type', MovementType::Reversal->value)->count())->toBe(1)
        ->and(DocumentReversal::count())->toBe(1)
        ->and(rvBalance($w))->toBe(0.0);
});

it('refuses to reverse a receipt whose stock was already used and changes nothing', function () {
    $w = rvWorld();
    $receipt = rvReceipt($w, '10');
    rvIssue($w, '8');

    expect(fn () => app(ReverseStockDocument::class)->execute($receipt, $w->manager, 'Salah input'))
        ->toThrow(DomainException::class, 'sudah terpakai');

    expect(DocumentReversal::count())->toBe(0)
        ->and(StockMovement::count())->toBe(2)
        ->and(AuditLog::where('action', 'goods_receipt.reversed')->count())->toBe(0)
        ->and(rvBalance($w))->toBe(2.0);
});

it('refuses to reverse a draft and requires a reason', function () {
    $w = rvWorld();
    $draft = rvDraftReceipt($w, '5');

    expect(fn () => app(ReverseStockDocument::class)->execute($draft, $w->manager, 'Alasan'))
        ->toThrow(DomainException::class);

    $posted = rvReceipt($w, '10');

    expect(fn () => app(ReverseStockDocument::class)->execute($posted, $w->manager, '   '))
        ->toThrow(DomainException::class);

    expect(DocumentReversal::count())->toBe(0);
});

it('is blocked while the warehouse is being counted', function () {
    $w = rvWorld();
    $receipt = rvReceipt($w, '10');

    $stocktake = Stocktake::create(['warehouse_id' => $w->a->id, 'created_by' => $w->staff->id]);
    app(StartStocktake::class)->execute($stocktake, $w->staff);

    expect(fn () => app(ReverseStockDocument::class)->execute($receipt, $w->manager, 'Salah input'))
        ->toThrow(WarehouseLockedException::class);

    expect(DocumentReversal::count())->toBe(0)->and(rvBalance($w))->toBe(10.0);
});

it('keeps balances reconcilable with the ledger after reversals', function () {
    $w = rvWorld();
    $receipt = rvReceipt($w, '10');
    $issue = rvIssue($w, '3');
    $action = app(ReverseStockDocument::class);

    $action->execute($issue, $w->manager, 'Salah pelanggan');
    $action->execute($receipt, $w->manager, 'Salah input');

    $ledger = (float) StockMovement::where('warehouse_id', $w->a->id)
        ->where('product_id', $w->p1->id)
        ->sum('quantity_delta');

    expect(rvBalance($w))->toBe($ledger)->and($ledger)->toBe(0.0);
});

it('lets only admin or manager reverse over HTTP', function () {
    $w = rvWorld();
    $receipt = rvReceipt($w, '10');
    $url = "/goods-receipts/{$receipt->id}/reverse";

    $this->actingAs($w->staff)->post($url, ['reason' => 'Salah input'])->assertForbidden();

    expect(DocumentReversal::count())->toBe(0)->and(rvBalance($w))->toBe(10.0);

    $this->actingAs($w->manager)->post($url, ['reason' => 'Salah input'])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(DocumentReversal::count())->toBe(1)->and(rvBalance($w))->toBe(0.0);

    $issueReceipt = rvReceipt($w, '4');
    $this->actingAs($w->admin)->post("/goods-receipts/{$issueReceipt->id}/reverse", ['reason' => 'Dobel'])
        ->assertSessionHas('success');
});

it('validates the reason and refuses a second reversal over HTTP', function () {
    $w = rvWorld();
    $receipt = rvReceipt($w, '10');
    $url = "/goods-receipts/{$receipt->id}/reverse";

    $this->actingAs($w->manager)->post($url, ['reason' => ''])->assertSessionHasErrors('reason');
    expect(DocumentReversal::count())->toBe(0);

    $this->actingAs($w->manager)->post($url, ['reason' => 'Salah input'])->assertSessionHas('success');
    $this->actingAs($w->manager)->post($url, ['reason' => 'Lagi'])->assertForbidden();

    expect(StockMovement::where('movement_type', MovementType::Reversal->value)->count())->toBe(1);

    $draft = rvDraftReceipt($w, '5');
    $this->actingAs($w->manager)->post("/goods-receipts/{$draft->id}/reverse", ['reason' => 'x'])->assertForbidden();
});

it('flags reversed documents in the list and on the detail page', function () {
    $w = rvWorld();
    $receipt = rvReceipt($w, '10');

    $this->actingAs($w->manager)->get("/goods-receipts/{$receipt->id}")->assertInertia(fn (Assert $page) => $page
        ->where('can.reverse', true)
        ->where('document.reversal', null));

    $this->actingAs($w->manager)->post("/goods-receipts/{$receipt->id}/reverse", ['reason' => 'Salah input']);

    $this->actingAs($w->manager)->get("/goods-receipts/{$receipt->id}")->assertInertia(fn (Assert $page) => $page
        ->where('can.reverse', false)
        ->where('document.reversal.reason', 'Salah input'));

    $this->actingAs($w->manager)->get('/goods-receipts')->assertInertia(fn (Assert $page) => $page
        ->where('documents.data.0.is_reversed', true));

    $this->actingAs($w->staff)->get("/goods-receipts/{$receipt->id}")->assertInertia(fn (Assert $page) => $page
        ->where('can.reverse', false));
});
