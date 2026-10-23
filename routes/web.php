<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\WarehouseController;

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::resource('categories', CategoryController::class)
        ->only(['index', 'store', 'update', 'destroy']);

    Route::resource('units', UnitController::class)
        ->only(['index', 'store', 'update', 'destroy']);

    Route::resource('products', ProductController::class)
        ->only(['index', 'store', 'update']);
    Route::patch('products/{product}/toggle-active', [ProductController::class, 'toggleActive'])
        ->name('products.toggle-active');

    Route::resource('suppliers', SupplierController::class)->only(['index', 'store', 'update']);
    Route::patch('suppliers/{supplier}/toggle-active', [SupplierController::class, 'toggleActive'])
        ->name('suppliers.toggle-active');

    Route::resource('warehouses', WarehouseController::class)->only(['index', 'store', 'update']);
    Route::patch('warehouses/{warehouse}/toggle-active', [WarehouseController::class, 'toggleActive'])
        ->name('warehouses.toggle-active');
});

require __DIR__ . '/settings.php';
