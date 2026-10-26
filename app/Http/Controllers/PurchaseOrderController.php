<?php

namespace App\Http\Controllers;

use App\Actions\Purchasing\ApprovePurchaseOrder;
use App\Actions\Purchasing\CancelPurchaseOrder;
use App\Actions\Purchasing\ClosePurchaseOrder;
use App\Actions\Purchasing\CreateReceiptFromPurchaseOrder;
use App\Actions\Purchasing\SubmitPurchaseOrder;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\InvalidPurchaseOrderStateException;
use App\Http\Requests\PurchaseOrderRequest;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use Closure;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    private function itemRows(array $data): array
    {
        return collect($data['items'])
            ->map(fn ($i) => [
                'product_id' => (int) $i['product_id'],
                'quantity' => $i['quantity'],
                'unit_cost' => $i['unit_cost'],
            ])
            ->all();
    }

    private function headerData(array $data): array
    {
        return [
            'supplier_id' => $data['supplier_id'],
            'warehouse_id' => $data['warehouse_id'],
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'expected_date' => $data['expected_date'] ?? null,
        ];
    }

    /** Kunci PO lalu pastikan masih draft (request lain mungkin baru mengubahnya). */
    private function lockedDraft(PurchaseOrder $order): PurchaseOrder
    {
        $locked = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== PurchaseOrderStatus::Draft) {
            throw new InvalidPurchaseOrderStateException($locked->status->value);
        }

        return $locked;
    }

    /** Jalankan action; error bisnis menjadi pesan toast, bukan halaman error. */
    private function run(Closure $callback, string $success): RedirectResponse
    {
        try {
            $callback();
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $ids = $user->accessibleWarehouseIds(); // null = semua gudang

        $search = trim((string) $request->query('search', ''));
        $status = PurchaseOrderStatus::tryFrom((string) $request->query('status'))?->value ?? 'all';
        $warehouseId = $request->integer('warehouse_id') ?: null;

        $orders = PurchaseOrder::query()
            ->select('purchase_orders.*')
            ->addSelect(['total_value' => PurchaseOrderItem::query()
                ->selectRaw('COALESCE(SUM(ROUND(quantity * unit_cost, 4)), 0)')
                ->whereColumn('purchase_order_items.purchase_order_id', 'purchase_orders.id')])
            ->with(['supplier:id,name', 'warehouse:id,code', 'creator:id,name'])
            ->withCount('items')
            ->when($ids !== null, fn ($q) => $q->whereIn('warehouse_id', $ids))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('number', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")
                ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$search}%"))))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('purchase-orders/index', [
            'orders' => $orders,
            'warehouses' => Warehouse::accessibleBy($user)->orderBy('code')->get(['id', 'code', 'name']),
            'filters' => [
                'search' => $search,
                'status' => $status,
                'warehouse_id' => $warehouseId ? (string) $warehouseId : 'all',
            ],
            'can' => ['create' => $user->can('create', PurchaseOrder::class)],
        ]);
    }

    private function formResponse(Request $request, ?PurchaseOrder $order): Response
    {
        $user = $request->user();

        if ($order) {
            Gate::authorize('update', $order);
            $order->load('items');
        } else {
            Gate::authorize('create', PurchaseOrder::class);
        }

        return Inertia::render('purchase-orders/form', [
            'document' => $order ? [
                'id' => $order->id,
                'number' => $order->number,
                'supplier_id' => $order->supplier_id,
                'warehouse_id' => $order->warehouse_id,
                'expected_date' => $order->expected_date?->toDateString(),
                'reference' => $order->reference,
                'notes' => $order->notes,
                'items' => $order->items->map(fn ($i) => [
                    'product_id' => $i->product_id,
                    'quantity' => (string) $i->quantity,
                    'unit_cost' => (string) $i->unit_cost,
                ])->all(),
            ] : null,
            'warehouses' => Warehouse::accessibleBy($user)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'products' => Product::query()
                ->where('is_active', true)
                ->with('unit:id,symbol')
                ->orderBy('name')
                ->get(['id', 'sku', 'name', 'unit_id']),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->formResponse($request, null);
    }

    public function edit(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        return $this->formResponse($request, $purchaseOrder);
    }

    public function store(PurchaseOrderRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $order = DB::transaction(function () use ($request, $data) {
            $order = PurchaseOrder::create([
                ...$this->headerData($data),
                'created_by' => $request->user()->id,
            ]);

            $order->items()->createMany($this->itemRows($data));

            return $order;
        });

        return redirect()->route('purchase-orders.show', $order)->with('success', 'Draft Purchase Order berhasil disimpan.');
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        Gate::authorize('view', $purchaseOrder);

        $user = $request->user();

        $purchaseOrder->load([
            'supplier:id,name',
            'warehouse:id,code,name',
            'creator:id,name',
            'submitter:id,name',
            'approver:id,name',
            'closer:id,name',
            'items.product:id,sku,name,unit_id',
            'items.product.unit:id,symbol',
        ]);

        $o = $purchaseOrder;

        $receipts = GoodsReceipt::query()
            ->where('purchase_order_id', $o->id)
            ->withCount('items')
            ->withExists('reversal as is_reversed')
            ->orderByDesc('id')
            ->get(['id', 'number', 'status', 'posted_at', 'created_at'])
            ->map(fn (GoodsReceipt $r) => [
                'id' => $r->id,
                'number' => $r->number,
                'status' => $r->status->value,
                'is_reversed' => (bool) $r->is_reversed,
                'items_count' => $r->items_count,
                'posted_at' => $r->posted_at,
                'created_at' => $r->created_at,
            ])->all();

        return Inertia::render('purchase-orders/show', [
            'document' => [
                'id' => $o->id,
                'number' => $o->number,
                'status' => $o->status->value,
                'reference' => $o->reference,
                'notes' => $o->notes,
                'expected_date' => $o->expected_date?->toDateString(),
                'submitted_at' => $o->submitted_at,
                'approved_at' => $o->approved_at,
                'closed_at' => $o->closed_at,
                'close_reason' => $o->close_reason,
                'completed_at' => $o->completed_at,
                'created_at' => $o->created_at,
                'supplier' => $o->supplier,
                'warehouse' => $o->warehouse,
                'creator' => $o->creator,
                'submitter' => $o->submitter,
                'approver' => $o->approver,
                'closer' => $o->closer,
                'total' => $o->items->reduce(fn ($carry, $i) => bcadd($carry, $i->lineTotal(), 4), '0'),
                'items' => $o->items->map(fn ($i) => [
                    'id' => $i->id,
                    'quantity' => (string) $i->quantity,
                    'unit_cost' => (string) $i->unit_cost,
                    'qty_received' => (string) $i->qty_received,
                    'outstanding' => $i->outstanding(),
                    'line_total' => $i->lineTotal(),
                    'product' => $i->product,
                ])->all(),
            ],
            'receipts' => $receipts,
            'can' => [
                'update' => $user->can('update', $o),
                'delete' => $user->can('delete', $o),
                'submit' => $user->can('submit', $o),
                'approve' => $user->can('approve', $o),
                'receive' => $user->can('receive', $o),
                'close' => $user->can('close', $o),
                'cancel' => $user->can('cancel', $o),
            ],
        ]);
    }

    public function update(PurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $data = $request->validated();

        try {
            DB::transaction(function () use ($purchaseOrder, $data) {
                $locked = $this->lockedDraft($purchaseOrder);

                $locked->update($this->headerData($data));
                $locked->items()->delete();
                $locked->items()->createMany($this->itemRows($data));
            });
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('purchase-orders.show', $purchaseOrder)->with('success', 'Draft berhasil diperbarui.');
    }

    public function destroy(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        Gate::authorize('delete', $purchaseOrder);

        try {
            DB::transaction(function () use ($purchaseOrder) {
                $locked = $this->lockedDraft($purchaseOrder);

                $locked->items()->delete();
                $locked->delete();
            });
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('purchase-orders.index')->with('success', 'Draft berhasil dihapus.');
    }

    public function submit(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        Gate::authorize('submit', $purchaseOrder);

        return $this->run(
            fn () => app(SubmitPurchaseOrder::class)->execute($purchaseOrder, $request->user()),
            config('stockpilot.po_requires_approval')
                ? 'Purchase Order diajukan dan menunggu approval.'
                : 'Purchase Order diajukan dan siap menerima barang.',
        );
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        Gate::authorize('approve', $purchaseOrder);

        return $this->run(
            fn () => app(ApprovePurchaseOrder::class)->execute($purchaseOrder, $request->user()),
            'Purchase Order disetujui.',
        );
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        Gate::authorize('cancel', $purchaseOrder);

        return $this->run(
            fn () => app(CancelPurchaseOrder::class)->execute($purchaseOrder, $request->user()),
            'Purchase Order dibatalkan.',
        );
    }

    public function close(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        // Otorisasi dulu, baru validasi.
        Gate::authorize('close', $purchaseOrder);

        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:255']],
            [],
            ['reason' => 'alasan penutupan'],
        );

        return $this->run(
            fn () => app(ClosePurchaseOrder::class)->execute($purchaseOrder, $request->user(), $data['reason']),
            'Purchase Order ditutup. Sisa pesanan tidak akan diterima.',
        );
    }

    public function createReceipt(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        Gate::authorize('receive', $purchaseOrder);

        try {
            $receipt = app(CreateReceiptFromPurchaseOrder::class)->execute($purchaseOrder, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('goods-receipts.show', $receipt)
            ->with('success', 'Draft penerimaan dibuat dari PO. Periksa jumlahnya lalu posting.');
    }
}
