<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWarehouseRequest;
use App\Http\Requests\UpdateWarehouseRequest;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;


class WarehouseController extends Controller
{
    public static function middleware(): array
    {
        return [new Middleware('can:manage-master-data', except: ['index'])];
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $status = in_array($request->query('status'), ['active', 'inactive'], true)
            ? $request->query('status')
            : 'all';

        $warehouses = Warehouse::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")))
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('code')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('warehouses/index', [
            'warehouses' => $warehouses,
            'filters' => ['search' => $search, 'status' => $status],
        ]);
    }

    public function store(StoreWarehouseRequest $request)
    {
        Warehouse::create($request->validated());

        return back()->with('success', 'Gudang berhasil ditambahkan.');
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse)
    {
        $warehouse->update($request->validated());

        return back()->with('success', 'Gudang berhasil diperbarui.');
    }

    public function toggleActive(Warehouse $warehouse)
    {
        $warehouse->update(['is_active' => !$warehouse->is_active]);

        return back()->with('success', $warehouse->is_active ? 'Gudang diaktifkan.' : 'Gudang dinonaktifkan.');
    }
}
