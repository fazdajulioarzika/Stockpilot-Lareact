<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\StockIssueController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\InventoryController;

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // Semua role yang login boleh membaca.
    Route::resource('categories', CategoryController::class)->only(['index']);
    Route::resource('units', UnitController::class)->only(['index']);
    Route::resource('products', ProductController::class)->only(['index']);
    Route::resource('suppliers', SupplierController::class)->only(['index']);
    Route::resource('warehouses', WarehouseController::class)->only(['index']);
    Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::get('stock-movements', [StockMovementController::class, 'index'])->name('stock-movements.index');

    Route::resource('goods-receipts', GoodsReceiptController::class)
        ->parameters(['goods-receipts' => 'goods_receipt']);
    Route::post('goods-receipts/{goods_receipt}/post', [GoodsReceiptController::class, 'post'])
        ->name('goods-receipts.post');

    Route::resource('stock-issues', StockIssueController::class)
        ->parameters(['stock-issues' => 'stock_issue']);
    Route::post('stock-issues/{stock_issue}/post', [StockIssueController::class, 'post'])
        ->name('stock-issues.post');

    // Hanya Admin yang boleh menulis (dicek di backend, bukan di React).
    Route::middleware('can:manage-master-data')->group(function () {
        Route::resource('categories', CategoryController::class)->only(['store', 'update', 'destroy']);
        Route::resource('units', UnitController::class)->only(['store', 'update', 'destroy']);
        Route::resource('products', ProductController::class)->only(['store', 'update']);
        Route::resource('suppliers', SupplierController::class)->only(['store', 'update']);
        Route::resource('warehouses', WarehouseController::class)->only(['store', 'update']);

        Route::patch('products/{product}/toggle-active', [ProductController::class, 'toggleActive'])
            ->name('products.toggle-active');
        Route::patch('suppliers/{supplier}/toggle-active', [SupplierController::class, 'toggleActive'])
            ->name('suppliers.toggle-active');
        Route::patch('warehouses/{warehouse}/toggle-active', [WarehouseController::class, 'toggleActive'])
            ->name('warehouses.toggle-active');
    });
});

require __DIR__ . '/settings.php';
