<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class CategoryController extends Controller
{
    public static function middleware(): array
    {
        return [new Middleware('can:manage-master-data', except: ['index'])];
    }
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));

        $categories = Category::query()
            ->withCount('products')
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('categories/index', [
            'categories' => $categories,
            'filters' => ['search' => $search],
        ]);
    }

    public function store(StoreCategoryRequest $request)
    {
        $name = $request->validated('name');

        Category::create([
            'name' => $name,
            'slug' => Str::slug($name),
        ]);

        return back()->with('success', 'Kategori berhasil ditambahkan.');
    }

    public function update(UpdateCategoryRequest $request, Category $category)
    {
        $name = $request->validated('name');

        $category->update([
            'name' => $name,
            'slug' => Str::slug($name),
        ]);

        return back()->with('success', 'Kategori berhasil diperbarui.');
    }

    public function destroy(Category $category)
    {
        // Master yang sudah dipakai produk tidak boleh dihapus (PRD 7.3).
        if ($category->products()->exists()) {
            return back()->with('error', 'Kategori sudah dipakai produk dan tidak dapat dihapus.');
        }

        $category->delete();

        return back()->with('success', 'Kategori berhasil dihapus.');
    }
}
