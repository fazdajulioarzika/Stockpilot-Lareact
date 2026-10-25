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
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\StocktakeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\UserController;

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {

    Route::middleware('can:manage-users')->group(function () {
        Route::resource('users', UserController::class)->only(['index', 'store', 'update']);
    });

    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('audit-logs', [AuditLogController::class, 'index'])
        ->middleware('can:view-audit-log')
        ->name('audit-logs.index');

    Route::resource('stocktakes', StocktakeController::class);
    Route::prefix('stocktakes/{stocktake}')->name('stocktakes.')->group(function () {
        Route::post('start', [StocktakeController::class, 'start'])->name('start');
        Route::post('count', [StocktakeController::class, 'count'])->name('count');
        Route::post('submit', [StocktakeController::class, 'submit'])->name('submit');
        Route::post('approve', [StocktakeController::class, 'approve'])->name('approve');
        Route::post('reject', [StocktakeController::class, 'reject'])->name('reject');
        Route::post('cancel', [StocktakeController::class, 'cancel'])->name('cancel');
    });

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

    Route::resource('transfers', StockTransferController::class)
        ->parameters(['transfers' => 'stock_transfer']);

    Route::prefix('transfers/{stock_transfer}')->name('transfers.')->group(function () {
        Route::post('submit', [StockTransferController::class, 'submit'])->name('submit');
        Route::post('approve', [StockTransferController::class, 'approve'])->name('approve');
        Route::post('dispatch', [StockTransferController::class, 'dispatchTransfer'])->name('dispatch');
        Route::post('receive', [StockTransferController::class, 'receive'])->name('receive');
        Route::post('return', [StockTransferController::class, 'returnRemainder'])->name('return');
        Route::post('cancel', [StockTransferController::class, 'cancel'])->name('cancel');
    });

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
