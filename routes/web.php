<?php

use App\Http\Controllers\CategoryController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::resource('categories', CategoryController::class)
        ->only(['index', 'store', 'update', 'destroy']);
});

require __DIR__ . '/settings.php';
