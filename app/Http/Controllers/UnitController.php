<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\UpdateUnitRequest;
use App\Models\Unit;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class UnitController extends Controller
{
    public static function middleware(): array
    {
        return [new Middleware('can:manage-master-data', except: ['index'])];
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));

        $units = Unit::query()
            ->withCount('products')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('symbol', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('units/index', [
            'units' => $units,
            'filters' => ['search' => $search],
        ]);
    }

    public function store(StoreUnitRequest $request)
    {
        Unit::create($request->validated());

        return back()->with('success', 'Satuan berhasil ditambahkan.');
    }

    public function update(UpdateUnitRequest $request, Unit $unit)
    {
        $unit->update($request->validated());

        return back()->with('success', 'Satuan berhasil diperbarui.');
    }

    public function destroy(Unit $unit)
    {
        if ($unit->products()->exists()) {
            return back()->with('error', 'Satuan sudah dipakai produk dan tidak dapat dihapus.');
        }

        $unit->delete();

        return back()->with('success', 'Satuan berhasil dihapus.');
    }
}
