<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ProductController extends Controller
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
        $categoryId = $request->integer('category_id') ?: null;

        $products = Product::query()
            ->with(['category:id,name', 'unit:id,name,symbol'])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('sku', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")))
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('products/index', [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'units' => Unit::orderBy('name')->get(['id', 'name', 'symbol']),
            'filters' => [
                'search' => $search,
                'status' => $status,
                'category_id' => $categoryId ? (string) $categoryId : 'all',
            ],
        ]);
    }

    public function store(StoreProductRequest $request)
    {
        Product::create($request->validated());

        return back()->with('success', 'Produk berhasil ditambahkan.');
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        $product->update($request->validated());

        return back()->with('success', 'Produk berhasil diperbarui.');
    }

    public function toggleActive(Product $product)
    {
        $product->update(['is_active' => !$product->is_active]);

        return back()->with(
            'success',
            $product->is_active ? 'Produk diaktifkan.' : 'Produk dinonaktifkan.'
        );
    }
}
