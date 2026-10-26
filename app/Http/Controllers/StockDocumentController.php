<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Exceptions\DocumentAlreadyPostedException;
use App\Http\Requests\StockDocumentRequest;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use App\Actions\Inventory\ReverseStockDocument;

abstract class StockDocumentController extends Controller
{
    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** @return array{title: string, basePath: string, route: string, view: string, type: string} */
    abstract protected function config(): array;

    /** Kolom header khusus dokumen, selain warehouse_id, reference, dan notes. */
    abstract protected function headerFields(): array;

    abstract protected function runPost(Model $doc, User $user): void;

    protected function extraRelations(): array
    {
        return [];
    }

    protected function extraFormProps(): array
    {
        return [];
    }

    private function documentFields(): array
    {
        return ['warehouse_id', 'reference', 'notes', ...$this->headerFields()];
    }

    private function itemRows(array $data): array
    {
        return collect($data['items'])
            ->map(fn ($i) => [
                'product_id' => (int) $i['product_id'],
                'quantity' => $i['quantity'],
                ...(isset($i['unit_cost']) ? ['unit_cost' => $i['unit_cost']] : []),
            ])
            ->all();
    }

    private function lockedDraft(Model $doc): Model
    {
        $locked = $this->modelClass()::query()->whereKey($doc->getKey())->lockForUpdate()->firstOrFail();

        // Status dicek ulang setelah dikunci: request lain mungkin baru saja memposting.
        if ($locked->status !== DocumentStatus::Draft) {
            throw new DocumentAlreadyPostedException();
        }

        return $locked;
    }

    protected function listDocuments(Request $request): Response
    {
        $user = $request->user();
        $model = $this->modelClass();
        $ids = $user->accessibleWarehouseIds(); // null = semua gudang

        $search = trim((string) $request->query('search', ''));
        $status = in_array($request->query('status'), ['draft', 'posted'], true)
            ? $request->query('status')
            : 'all';
        $warehouseId = $request->integer('warehouse_id') ?: null;

        $documents = $model::query()
            ->with(['warehouse:id,code,name', 'creator:id,name'])
            ->withCount('items')
            ->withExists('reversal as is_reversed')
            ->when($ids !== null, fn ($q) => $q->whereIn('warehouse_id', $ids))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('number', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render($this->config()['view'] . '/index', [
            'meta' => $this->config(),
            'documents' => $documents,
            'warehouses' => Warehouse::accessibleBy($user)->orderBy('code')->get(['id', 'code', 'name']),
            'filters' => [
                'search' => $search,
                'status' => $status,
                'warehouse_id' => $warehouseId ? (string) $warehouseId : 'all',
            ],
            'can' => ['create' => $user->can('create', $model)],
        ]);
    }

    protected function showForm(Request $request, ?Model $doc = null): Response
    {
        $user = $request->user();

        if ($doc) {
            Gate::authorize('update', $doc);
            $doc->load('items');
        } else {
            Gate::authorize('create', $this->modelClass());
        }

        return Inertia::render($this->config()['view'] . '/form', [
            'meta' => $this->config(),
            'document' => $doc?->toArray(),
            'warehouses' => Warehouse::accessibleBy($user)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'products' => Product::query()
                ->where('is_active', true)
                ->with('unit:id,symbol')
                ->orderBy('name')
                ->get(['id', 'sku', 'name', 'unit_id']),
            ...$this->extraFormProps(),
        ]);
    }

    protected function storeDocument(StockDocumentRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $doc = DB::transaction(function () use ($request, $data) {
            $doc = $this->modelClass()::create([
                ...Arr::only($data, $this->documentFields()),
                'created_by' => $request->user()->id,
            ]);

            $doc->items()->createMany($this->itemRows($data));

            return $doc;
        });

        return redirect()
            ->route($this->config()['route'] . '.show', $doc)
            ->with('success', "Draft {$this->config()['title']} berhasil disimpan.");
    }

    protected function showDocument(Request $request, Model $doc): Response
    {
        Gate::authorize('view', $doc);

        $user = $request->user();

        $doc->load([
            'warehouse:id,code,name',
            'creator:id,name',
            'poster:id,name',
            'items.product:id,sku,name,unit_id',
            'items.product.unit:id,symbol',
            'reversal.reverser:id,name',
            ...$this->extraRelations(),
        ]);

        return Inertia::render($this->config()['view'] . '/show', [
            'meta' => $this->config(),
            'document' => $doc->toArray(),
            'can' => [
                'update' => $user->can('update', $doc),
                'post' => $user->can('post', $doc),
                'reverse' => $user->can('reverse', $doc),
            ],
        ]);
    }

    protected function updateDocument(StockDocumentRequest $request, Model $doc): RedirectResponse
    {
        $data = $request->validated();

        try {
            DB::transaction(function () use ($doc, $data) {
                $locked = $this->lockedDraft($doc);

                $locked->update(Arr::only($data, $this->documentFields()));
                $locked->items()->delete();
                $locked->items()->createMany($this->itemRows($data));
            });
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route($this->config()['route'] . '.show', $doc)
            ->with('success', 'Draft berhasil diperbarui.');
    }

    protected function destroyDocument(Model $doc): RedirectResponse
    {
        Gate::authorize('delete', $doc);

        try {
            DB::transaction(function () use ($doc) {
                $locked = $this->lockedDraft($doc);

                $locked->items()->delete();
                $locked->delete();
            });
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route($this->config()['route'] . '.index')
            ->with('success', 'Draft berhasil dihapus.');
    }

    protected function postDocument(Request $request, Model $doc): RedirectResponse
    {
        Gate::authorize('post', $doc);

        try {
            $this->runPost($doc, $request->user());
        } catch (DomainException $e) {
            // Termasuk InsufficientStockException dan DocumentAlreadyPostedException.
            // Transaksi sudah di-rollback di dalam action, saldo tidak berubah.
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Dokumen berhasil diposting. Stok sudah diperbarui.');
    }
    protected function reverseDocument(Request $request, Model $doc): RedirectResponse
    {
        Gate::authorize('reverse', $doc);

        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:255']],
            [],
            ['reason' => 'alasan pembalikan'],
        );

        try {
            app(ReverseStockDocument::class)->execute($doc, $request->user(), $data['reason']);
        } catch (DomainException $e) {
            // Transaksi sudah di-rollback di dalam action, saldo tidak berubah.
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Dokumen dibalik. Stok disesuaikan dan tercatat di ledger.');
    }
}
