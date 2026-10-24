<?php

namespace App\Http\Controllers;

use App\Actions\Inventory\ApproveStockTransfer;
use App\Actions\Inventory\CancelStockTransfer;
use App\Actions\Inventory\DispatchStockTransfer;
use App\Actions\Inventory\ReceiveStockTransfer;
use App\Actions\Inventory\ReturnTransferRemainder;
use App\Actions\Inventory\SubmitStockTransfer;
use App\Enums\TransferStatus;
use App\Exceptions\InvalidTransferStateException;
use App\Http\Requests\StockTransferRequest;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use Closure;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class StockTransferController extends Controller
{
    private function itemRows(array $data): array
    {
        return collect($data['items'])
            ->map(fn ($i) => ['product_id' => (int) $i['product_id'], 'quantity' => $i['quantity']])
            ->all();
    }

    private function headerData(array $data): array
    {
        return Arr::only($data, ['source_warehouse_id', 'destination_warehouse_id', 'reference', 'notes']);
    }

    /** Kunci transfer lalu pastikan masih draft (request lain mungkin baru mengubahnya). */
    private function lockedDraft(StockTransfer $transfer): StockTransfer
    {
        $locked = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== TransferStatus::Draft) {
            throw new InvalidTransferStateException($locked->status->value);
        }

        return $locked;
    }

    /** Jalankan action; error bisnis menjadi pesan toast, bukan halaman error. */
    private function run(Closure $callback, string $success): RedirectResponse
    {
        try {
            $callback();
        } catch (DomainException $e) {
            // Transaksi sudah di-rollback di dalam action, saldo tidak berubah.
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $ids = $user->accessibleWarehouseIds(); // null = semua gudang

        $search = trim((string) $request->query('search', ''));
        $status = TransferStatus::tryFrom((string) $request->query('status'))?->value ?? 'all';
        $warehouseId = $request->integer('warehouse_id') ?: null;

        $transfers = StockTransfer::query()
            ->with(['source:id,code', 'destination:id,code', 'creator:id,name'])
            ->withCount('items')
            ->when($ids !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('source_warehouse_id', $ids)
                ->orWhereIn('destination_warehouse_id', $ids)))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('number', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($warehouseId, fn ($q) => $q->where(fn ($w) => $w
                ->where('source_warehouse_id', $warehouseId)
                ->orWhere('destination_warehouse_id', $warehouseId)))
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('transfers/index', [
            'transfers' => $transfers,
            'warehouses' => Warehouse::accessibleBy($user)->orderBy('code')->get(['id', 'code', 'name']),
            'filters' => [
                'search' => $search,
                'status' => $status,
                'warehouse_id' => $warehouseId ? (string) $warehouseId : 'all',
            ],
            'can' => ['create' => $user->can('create', StockTransfer::class)],
        ]);
    }

    private function formResponse(Request $request, ?StockTransfer $transfer): Response
    {
        $user = $request->user();

        if ($transfer) {
            Gate::authorize('update', $transfer);
            $transfer->load('items');
        } else {
            Gate::authorize('create', StockTransfer::class);
        }

        return Inertia::render('transfers/form', [
            'document' => $transfer ? [
                ...$transfer->only([
                    'id', 'number', 'source_warehouse_id', 'destination_warehouse_id', 'reference', 'notes',
                ]),
                'items' => $transfer->items->map(fn ($i) => [
                    'product_id' => $i->product_id,
                    'quantity' => (string) $i->quantity,
                ])->all(),
            ] : null,
            // Sumber: hanya gudang user. Tujuan: semua gudang aktif.
            'sources' => Warehouse::accessibleBy($user)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'destinations' => Warehouse::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
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

    public function edit(Request $request, StockTransfer $stockTransfer): Response
    {
        return $this->formResponse($request, $stockTransfer);
    }

    public function store(StockTransferRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $transfer = DB::transaction(function () use ($request, $data) {
            $transfer = StockTransfer::create([
                ...$this->headerData($data),
                'created_by' => $request->user()->id,
            ]);

            $transfer->items()->createMany($this->itemRows($data));

            return $transfer;
        });

        return redirect()->route('transfers.show', $transfer)->with('success', 'Draft transfer berhasil disimpan.');
    }

    public function show(Request $request, StockTransfer $stockTransfer): Response
    {
        Gate::authorize('view', $stockTransfer);

        $user = $request->user();

        $stockTransfer->load([
            'source:id,code,name',
            'destination:id,code,name',
            'creator:id,name',
            'approver:id,name',
            'dispatcher:id,name',
            'items.product:id,sku,name,unit_id',
            'items.product.unit:id,symbol',
        ]);

        $t = $stockTransfer;

        return Inertia::render('transfers/show', [
            'document' => [
                'id' => $t->id,
                'number' => $t->number,
                'status' => $t->status->value,
                'reference' => $t->reference,
                'notes' => $t->notes,
                'submitted_at' => $t->submitted_at,
                'approved_at' => $t->approved_at,
                'dispatched_at' => $t->dispatched_at,
                'completed_at' => $t->completed_at,
                'created_at' => $t->created_at,
                'source' => $t->source,
                'destination' => $t->destination,
                'creator' => $t->creator,
                'approver' => $t->approver,
                'dispatcher' => $t->dispatcher,
                'items' => $t->items->map(fn ($i) => [
                    'id' => $i->id,
                    'quantity' => (string) $i->quantity,
                    'qty_sent' => (string) $i->qty_sent,
                    'qty_received' => (string) $i->qty_received,
                    'qty_returned' => (string) $i->qty_returned,
                    'remaining' => $i->remaining(),
                    'product' => $i->product,
                ])->all(),
            ],
            'can' => [
                'update' => $user->can('update', $t),
                'delete' => $user->can('delete', $t),
                'submit' => $user->can('submit', $t),
                'approve' => $user->can('approve', $t),
                'dispatch' => $user->can('dispatch', $t),
                'receive' => $user->can('receive', $t),
                'returnToSource' => $user->can('returnToSource', $t),
                'cancel' => $user->can('cancel', $t),
            ],
        ]);
    }

    public function update(StockTransferRequest $request, StockTransfer $stockTransfer): RedirectResponse
    {
        $data = $request->validated();

        try {
            DB::transaction(function () use ($stockTransfer, $data) {
                $locked = $this->lockedDraft($stockTransfer);

                $locked->update($this->headerData($data));
                $locked->items()->delete();
                $locked->items()->createMany($this->itemRows($data));
            });
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('transfers.show', $stockTransfer)->with('success', 'Draft berhasil diperbarui.');
    }

    public function destroy(StockTransfer $stockTransfer): RedirectResponse
    {
        Gate::authorize('delete', $stockTransfer);

        try {
            DB::transaction(function () use ($stockTransfer) {
                $locked = $this->lockedDraft($stockTransfer);

                $locked->items()->delete();
                $locked->delete();
            });
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('transfers.index')->with('success', 'Draft berhasil dihapus.');
    }

    public function submit(Request $request, StockTransfer $stockTransfer): RedirectResponse
    {
        Gate::authorize('submit', $stockTransfer);

        return $this->run(
            fn () => app(SubmitStockTransfer::class)->execute($stockTransfer, $request->user()),
            config('stockpilot.transfer_requires_approval')
                ? 'Transfer diajukan dan menunggu approval.'
                : 'Transfer diajukan dan siap dikirim.',
        );
    }

    public function approve(Request $request, StockTransfer $stockTransfer): RedirectResponse
    {
        Gate::authorize('approve', $stockTransfer);

        return $this->run(
            fn () => app(ApproveStockTransfer::class)->execute($stockTransfer, $request->user()),
            'Transfer disetujui.',
        );
    }

    public function dispatchTransfer(Request $request, StockTransfer $stockTransfer): RedirectResponse
    {
        Gate::authorize('dispatch', $stockTransfer);

        return $this->run(
            fn () => app(DispatchStockTransfer::class)->execute($stockTransfer, $request->user()),
            'Transfer dikirim. Stok gudang sumber sudah berkurang.',
        );
    }

    public function receive(Request $request, StockTransfer $stockTransfer): RedirectResponse
    {
        // Otorisasi dulu, baru validasi.
        Gate::authorize('receive', $stockTransfer);

        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.item_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:999999999', 'decimal:0,3'],
        ], [], [
            'lines' => 'item yang diterima',
            'lines.*.quantity' => 'kuantitas',
        ]);

        return $this->run(
            fn () => app(ReceiveStockTransfer::class)->execute(
                $stockTransfer,
                $request->user(),
                $data['lines'],
                $data['idempotency_key'],
            ),
            'Penerimaan dicatat. Stok gudang tujuan sudah bertambah.',
        );
    }

    public function returnRemainder(Request $request, StockTransfer $stockTransfer): RedirectResponse
    {
        Gate::authorize('returnToSource', $stockTransfer);

        return $this->run(
            fn () => app(ReturnTransferRemainder::class)->execute($stockTransfer, $request->user()),
            'Sisa barang dikembalikan ke gudang sumber dan transfer ditutup.',
        );
    }

    public function cancel(Request $request, StockTransfer $stockTransfer): RedirectResponse
    {
        Gate::authorize('cancel', $stockTransfer);

        return $this->run(
            fn () => app(CancelStockTransfer::class)->execute($stockTransfer, $request->user()),
            'Transfer dibatalkan.',
        );
    }
}
