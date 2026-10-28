<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BoardController;
use App\Http\Controllers\Admin\CategoriesController;
use App\Http\Controllers\Admin\CurrenciesController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\OrdersController;
use App\Http\Controllers\Admin\PaymentsController;
use App\Http\Controllers\Admin\ShippingController;
use App\Http\Controllers\Admin\ProductsController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\StockAlertsController;
use App\Http\Controllers\Admin\WebsiteController;
use App\Http\Controllers\Admin\TranslationsController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\I18nController;
use App\Http\Controllers\Api\TapWebhookController;
use App\Http\Controllers\Api\StockAlertController;
use App\Http\Controllers\Api\StorefrontController;
use Illuminate\Support\Facades\Route;

/*
 | JSON API. Success: {ok:true, …}. Failure: {ok:false, error:{code, message, fields?}}.
 | Every admin route sits behind `admin` (signed in) and a role check – the server is the authority.
 */

Route::get('health', fn () => response()->json(['ok' => true, 'time' => now()->toIso8601String()]));

// ---- Storefront (public) ----
Route::get('storefront', [StorefrontController::class, 'index']);
Route::post('stock-alerts', [StockAlertController::class, 'store']);
Route::post('cart/hold', [CheckoutController::class, 'hold']);
Route::post('checkout/quote', [CheckoutController::class, 'quote']);
Route::post('checkout', [CheckoutController::class, 'place']);
Route::get('orders/{token}', [CheckoutController::class, 'show'])->where('token', '[a-f0-9]{40}');
Route::post('orders/{token}/pay', [CheckoutController::class, 'pay'])->where('token', '[a-f0-9]{40}');
// Tap → us (no Origin header; exempt in config/store.php). The charge is always fetched again from Tap.
Route::post('payments/tap/webhook', [TapWebhookController::class, 'handle']);
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
        Route::get('products/{id}/stock-alerts', [StockAlertsController::class, 'index'])->whereNumber('id');

    });

    // Orders – the admin and the orders board on the website (/staff). Shop staff only get these.
    Route::middleware('role:'.implode(',', \App\Support\Roles::BOARD))->group(function () {
        Route::get('orders', [OrdersController::class, 'index']);
        Route::get('orders/{id}', [OrdersController::class, 'show'])->whereNumber('id');
        Route::post('orders/{id}/status', [OrdersController::class, 'status'])->whereNumber('id');
        Route::post('orders/{id}/note', [OrdersController::class, 'note'])->whereNumber('id');
        Route::post('orders/{id}/prepared', [OrdersController::class, 'prepared'])->whereNumber('id');
        Route::post('orders/{id}/check-payment', [OrdersController::class, 'refreshPayment'])->whereNumber('id');
        Route::post('orders/{id}/ship', [OrdersController::class, 'ship'])->whereNumber('id');
        Route::get('orders/{id}/label', [OrdersController::class, 'label'])->whereNumber('id');
        Route::post('orders/{id}/track', [OrdersController::class, 'track'])->whereNumber('id');
        Route::get('orders/{id}/invoice', [BoardController::class, 'invoice'])->whereNumber('id');
        Route::post('orders/{id}/payment-link', [BoardController::class, 'paymentLink'])->whereNumber('id');
        Route::get('board/products', [BoardController::class, 'products']);
        Route::post('board/quote', [BoardController::class, 'quote']);
        Route::post('board/orders', [BoardController::class, 'store']);
        Route::get('board/pulse', [BoardController::class, 'pulse']);
        Route::get('board/push-key', [BoardController::class, 'pushKey']);
        Route::post('board/push', [BoardController::class, 'pushSubscribe']);
        Route::post('board/push/remove', [BoardController::class, 'pushUnsubscribe']);
        Route::post('board/push/test', [BoardController::class, 'pushTest']);
    });
    // Money received outside the website (WhatsApp / Benefit / cash): shop staff and managers.
    Route::middleware('role:shop_staff,manager+')->post('orders/{id}/paid', [OrdersController::class, 'markPaid'])->whereNumber('id');

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
        Route::post('stock-alerts/{id}/done', [StockAlertsController::class, 'done'])->whereNumber('id');
        Route::delete('stock-alerts/{id}', [StockAlertsController::class, 'destroy'])->whereNumber('id');
        Route::post('orders/{id}/cancel', [OrdersController::class, 'cancel'])->whereNumber('id');
        Route::post('orders/{id}/refund', [OrdersController::class, 'refund'])->whereNumber('id');
        Route::post('orders/{id}/refunds/{refundId}/check', [OrdersController::class, 'refundCheck'])->whereNumber(['id', 'refundId']);
        Route::post('orders/{id}/cancel-shipment', [OrdersController::class, 'cancelShipment'])->whereNumber('id');
        Route::get('website', [WebsiteController::class, 'index']);
        Route::put('website', [WebsiteController::class, 'save']);
    });

    Route::middleware('role:owner')->group(function () {
        Route::get('staff', [StaffController::class, 'index']);
        Route::post('staff', [StaffController::class, 'store']);
        Route::put('staff/{id}', [StaffController::class, 'update'])->whereNumber('id');
        Route::get('activity', [ActivityController::class, 'index']);
        Route::get('currencies', [CurrenciesController::class, 'index']);
        Route::put('currencies', [CurrenciesController::class, 'save']);
        Route::get('payments', [PaymentsController::class, 'index']);
        Route::put('payments', [PaymentsController::class, 'save']);
        Route::post('payments/test', [PaymentsController::class, 'test']);
        Route::post('payments/apple-pay-file', [PaymentsController::class, 'applePayFile']);
        Route::get('shipping', [ShippingController::class, 'index']);
        Route::put('shipping', [ShippingController::class, 'save']);
        Route::post('shipping/test', [ShippingController::class, 'test']);
    });
});
