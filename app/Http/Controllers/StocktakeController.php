<?php

namespace App\Http\Controllers;

use App\Actions\Inventory\CancelStocktake;
use App\Actions\Inventory\PostStocktake;
use App\Actions\Inventory\RecordStocktakeCount;
use App\Actions\Inventory\RejectStocktake;
use App\Actions\Inventory\StartStocktake;
use App\Actions\Inventory\SubmitStocktakeForApproval;
use App\Enums\StocktakeStatus;
use App\Exceptions\InvalidStocktakeStateException;
use App\Http\Requests\StocktakeRequest;
use App\Models\Product;
use App\Models\Stocktake;
use App\Models\Warehouse;
use Closure;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class StocktakeController extends Controller
{
    /** Kunci opname lalu pastikan masih draft (request lain mungkin baru mengubahnya). */
    private function lockedDraft(Stocktake $stocktake): Stocktake
    {
        $locked = Stocktake::query()->whereKey($stocktake->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== StocktakeStatus::Draft) {
            throw new InvalidStocktakeStateException($locked->status->value);
        }

        return $locked;
    }

    private function itemRows(array $data): array
    {
        return collect($data['product_ids'] ?? [])
            ->map(fn ($id) => ['product_id' => (int) $id])
            ->all();
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
        $status = StocktakeStatus::tryFrom((string) $request->query('status'))?->value ?? 'all';
        $warehouseId = $request->integer('warehouse_id') ?: null;

        $stocktakes = Stocktake::query()
            ->with(['warehouse:id,code,name', 'creator:id,name'])
            ->withCount('items')
            ->when($ids !== null, fn ($q) => $q->whereIn('warehouse_id', $ids))
            ->when($search !== '', fn ($q) => $q->where('number', 'like', "%{$search}%"))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('stocktakes/index', [
            'stocktakes' => $stocktakes,
            'warehouses' => Warehouse::accessibleBy($user)->orderBy('code')->get(['id', 'code', 'name']),
            'filters' => [
                'search' => $search,
                'status' => $status,
                'warehouse_id' => $warehouseId ? (string) $warehouseId : 'all',
            ],
            'can' => ['create' => $user->can('create', Stocktake::class)],
        ]);
    }

    private function formResponse(Request $request, ?Stocktake $stocktake): Response
    {
        $user = $request->user();

        if ($stocktake) {
            Gate::authorize('update', $stocktake);
            $stocktake->load('items');
        } else {
            Gate::authorize('create', Stocktake::class);
        }

        return Inertia::render('stocktakes/form', [
            'document' => $stocktake ? [
                'id' => $stocktake->id,
                'number' => $stocktake->number,
                'warehouse_id' => $stocktake->warehouse_id,
                'notes' => $stocktake->notes,
                'product_ids' => $stocktake->items->pluck('product_id')->all(),
            ] : null,
            'warehouses' => Warehouse::accessibleBy($user)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'products' => Product::query()->where('is_active', true)->orderBy('name')->get(['id', 'sku', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->formResponse($request, null);
    }

    public function edit(Request $request, Stocktake $stocktake): Response
    {
        return $this->formResponse($request, $stocktake);
    }

    public function store(StocktakeRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $stocktake = DB::transaction(function () use ($request, $data) {
            $stocktake = Stocktake::create([
                'warehouse_id' => $data['warehouse_id'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $stocktake->items()->createMany($this->itemRows($data));

            return $stocktake;
        });

        return redirect()->route('stocktakes.show', $stocktake)->with('success', 'Draft opname berhasil disimpan.');
    }

    public function show(Request $request, Stocktake $stocktake): Response
    {
        Gate::authorize('view', $stocktake);

        $user = $request->user();

        $stocktake->load([
            'warehouse:id,code,name',
            'creator:id,name',
            'starter:id,name',
            'submitter:id,name',
            'approver:id,name',
            'items.product:id,sku,name,unit_id',
            'items.product.unit:id,symbol',
        ]);

        $s = $stocktake;

        return Inertia::render('stocktakes/show', [
            'document' => [
                'id' => $s->id,
                'number' => $s->number,
                'status' => $s->status->value,
                'notes' => $s->notes,
                'rejection_note' => $s->rejection_note,
                'started_at' => $s->started_at,
                'submitted_at' => $s->submitted_at,
                'posted_at' => $s->posted_at,
                'completed_at' => $s->completed_at,
                'created_at' => $s->created_at,
                'warehouse' => $s->warehouse,
                'creator' => $s->creator,
                'starter' => $s->starter,
                'submitter' => $s->submitter,
                'approver' => $s->approver,
                'items' => $s->items->sortBy(fn ($i) => $i->product->sku)->values()->map(fn ($i) => [
                    'id' => $i->id,
                    'system_qty' => $i->system_qty === null ? null : (string) $i->system_qty,
                    'counted_qty' => $i->counted_qty === null ? null : (string) $i->counted_qty,
                    'difference' => $i->difference === null ? null : (string) $i->difference,
                    'reason' => $i->reason,
                    'product' => $i->product,
                ])->all(),
            ],
            'can' => [
                'update' => $user->can('update', $s),
                'delete' => $user->can('delete', $s),
                'start' => $user->can('start', $s),
                'count' => $user->can('count', $s),
                'submit' => $user->can('submit', $s),
                'approve' => $user->can('approve', $s),
                'reject' => $user->can('reject', $s),
                'cancel' => $user->can('cancel', $s),
            ],
        ]);
    }

    public function update(StocktakeRequest $request, Stocktake $stocktake): RedirectResponse
    {
        $data = $request->validated();

        try {
            DB::transaction(function () use ($stocktake, $data) {
                $locked = $this->lockedDraft($stocktake);

                $locked->update([
                    'warehouse_id' => $data['warehouse_id'],
                    'notes' => $data['notes'] ?? null,
                ]);
                $locked->items()->delete();
                $locked->items()->createMany($this->itemRows($data));
            });
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('stocktakes.show', $stocktake)->with('success', 'Draft berhasil diperbarui.');
    }

    public function destroy(Stocktake $stocktake): RedirectResponse
    {
        Gate::authorize('delete', $stocktake);

        try {
            DB::transaction(function () use ($stocktake) {
                $locked = $this->lockedDraft($stocktake);

                $locked->items()->delete();
                $locked->delete();
            });
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('stocktakes.index')->with('success', 'Draft berhasil dihapus.');
    }

    public function start(Request $request, Stocktake $stocktake): RedirectResponse
    {
        Gate::authorize('start', $stocktake);

        return $this->run(
            fn () => app(StartStocktake::class)->execute($stocktake, $request->user()),
            'Penghitungan dimulai. Transaksi stok di gudang ini dikunci sampai opname selesai.',
        );
    }

    public function count(Request $request, Stocktake $stocktake): RedirectResponse
    {
        // Otorisasi dulu, baru validasi.
        Gate::authorize('count', $stocktake);

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:1000'],
            'lines.*.item_id' => ['required', 'integer', 'distinct'],
            'lines.*.counted_qty' => ['nullable', 'numeric', 'min:0', 'max:999999999', 'decimal:0,3'],
            'lines.*.reason' => ['nullable', 'string', 'max:255'],
        ], [], [
            'lines' => 'hasil hitung',
            'lines.*.counted_qty' => 'hasil hitung',
            'lines.*.reason' => 'alasan',
        ]);

        return $this->run(
            fn () => app(RecordStocktakeCount::class)->execute($stocktake, $request->user(), $data['lines']),
            'Hasil hitung disimpan.',
        );
    }

    public function submit(Request $request, Stocktake $stocktake): RedirectResponse
    {
        Gate::authorize('submit', $stocktake);

        return $this->run(
            fn () => app(SubmitStocktakeForApproval::class)->execute($stocktake, $request->user()),
            'Opname diajukan dan menunggu approval.',
        );
    }

    public function approve(Request $request, Stocktake $stocktake): RedirectResponse
    {
        Gate::authorize('approve', $stocktake);

        return $this->run(
            fn () => app(PostStocktake::class)->execute($stocktake, $request->user()),
            'Opname disetujui dan diposting. Penyesuaian stok sudah dicatat, gudang dibuka kembali.',
        );
    }

    public function reject(Request $request, Stocktake $stocktake): RedirectResponse
    {
        Gate::authorize('reject', $stocktake);

        $data = $request->validate(
            ['note' => ['required', 'string', 'max:1000']],
            [],
            ['note' => 'catatan penolakan'],
        );

        return $this->run(
            fn () => app(RejectStocktake::class)->execute($stocktake, $request->user(), $data['note']),
            'Opname dikembalikan untuk dihitung ulang.',
        );
    }

    public function cancel(Request $request, Stocktake $stocktake): RedirectResponse
    {
        Gate::authorize('cancel', $stocktake);

        return $this->run(
            fn () => app(CancelStocktake::class)->execute($stocktake, $request->user()),
            'Opname dibatalkan. Gudang dibuka kembali.',
        );
    }
}
