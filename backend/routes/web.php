<?php

use App\Http\Controllers\Api\ImageController;
use App\Http\Controllers\Web\ProductPageController;
use App\Http\Controllers\Web\SeoController;
use Illuminate\Support\Facades\Route;

// The storefront and the admin are static files served by the web server (Hostinger: public_html + .htaccess).
// Laravel answers /api/* (routes/api.php), makes resized image copies on first request, and builds the pages
// that must carry server-side tags (product pages for link previews and Google, sitemap, robots):
Route::get('img/og/{path}', [ImageController::class, 'og'])->where('path', '(uploads|assets)/.+');
Route::get('img/{w}/{path}', [ImageController::class, 'show'])->whereNumber('w')->where('path', '(uploads|assets)/.+');
Route::get('product/{slug}', [ProductPageController::class, 'show'])->where('slug', '.+');
Route::get('sitemap.xml', [SeoController::class, 'sitemap']);
Route::get('robots.txt', [SeoController::class, 'robots']);

// Apple Pay domain verification (the file is uploaded in Admin › Settings › Payments).
Route::get('.well-known/apple-developer-merchantid-domain-association', function () {
    $text = \App\Support\Settings::get('payments')['applePay']['domainFile'] ?? '';
    abort_if($text === '', 404);

    return response($text, 200, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-cache']);
});
