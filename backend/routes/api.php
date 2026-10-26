<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CategoriesController;
use App\Http\Controllers\Admin\CurrenciesController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\ProductsController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\TranslationsController;
use App\Http\Controllers\Api\I18nController;
use App\Http\Controllers\Api\StorefrontController;
use Illuminate\Support\Facades\Route;

/*
 | JSON API. Success: {ok:true, …}. Failure: {ok:false, error:{code, message, fields?}}.
 | Every admin route sits behind `admin` (signed in) and a role check – the server is the authority.
 */

Route::get('health', fn () => response()->json(['ok' => true, 'time' => now()->toIso8601String()]));

// ---- Storefront (public) ----
Route::get('storefront', [StorefrontController::class, 'index']);
Route::post('i18n/missing', [I18nController::class, 'missing']);
Route::get('i18n/overrides', [I18nController::class, 'overrides']);

// ---- Admin: sign-in (public) ----
Route::prefix('admin')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('forgot', [AuthController::class, 'forgot']);
    Route::post('reset', [AuthController::class, 'reset']);
    Route::get('i18n.js', [TranslationsController::class, 'adminScript']);
});

// ---- Admin: signed in ----
Route::prefix('admin')->middleware('admin')->group(function () {
    // Every role (team roles included) may change their own password and report untranslated admin text.
    Route::post('password', [AuthController::class, 'password']);
    Route::post('i18n/missing', [TranslationsController::class, 'reportAdmin']);

    Route::middleware('role:staff')->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::get('translations', [TranslationsController::class, 'index']);
        Route::get('translations/settings', [TranslationsController::class, 'settings']);

        Route::get('media', [MediaController::class, 'index']);
        Route::get('media/{id}', [MediaController::class, 'show'])->whereNumber('id');
        Route::post('media', [MediaController::class, 'store']);
        Route::put('media/{id}', [MediaController::class, 'update'])->whereNumber('id');

        Route::get('products', [ProductsController::class, 'index']);
        Route::get('products/{id}', [ProductsController::class, 'show'])->whereNumber('id');
        Route::get('categories', [CategoriesController::class, 'index']);
    });

    Route::middleware('role:manager')->group(function () {
        Route::put('translations', [TranslationsController::class, 'save']);
        Route::post('translations/reset', [TranslationsController::class, 'reset']);
        Route::put('translations/settings', [TranslationsController::class, 'settings']);

        Route::post('uploads', [MediaController::class, 'upload']);
        Route::post('media/{id}/replace', [MediaController::class, 'replace'])->whereNumber('id');
        Route::delete('media/{id}', [MediaController::class, 'destroy'])->whereNumber('id');
        Route::post('media/bulk-delete', [MediaController::class, 'bulkDelete']);

        Route::post('products', [ProductsController::class, 'store']);
        Route::put('products/{id}', [ProductsController::class, 'update'])->whereNumber('id');
        Route::post('products/{id}/stock', [ProductsController::class, 'stock'])->whereNumber('id');
        Route::post('products/bulk', [ProductsController::class, 'bulk']);
        Route::delete('products/{id}', [ProductsController::class, 'destroy'])->whereNumber('id');
        Route::post('categories', [CategoriesController::class, 'store']);
        Route::put('categories/{id}', [CategoriesController::class, 'update'])->whereNumber('id');
        Route::post('categories/order', [CategoriesController::class, 'order']);
        Route::delete('categories/{id}', [CategoriesController::class, 'destroy'])->whereNumber('id');
    });

    Route::middleware('role:owner')->group(function () {
        Route::get('staff', [StaffController::class, 'index']);
        Route::post('staff', [StaffController::class, 'store']);
        Route::put('staff/{id}', [StaffController::class, 'update'])->whereNumber('id');
        Route::get('activity', [ActivityController::class, 'index']);
        Route::get('currencies', [CurrenciesController::class, 'index']);
        Route::put('currencies', [CurrenciesController::class, 'save']);
    });
});
