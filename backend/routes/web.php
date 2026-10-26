<?php

use App\Http\Controllers\Api\ImageController;
use Illuminate\Support\Facades\Route;

// The storefront and the admin are static files served by the web server (Hostinger: public_html + .htaccess).
// Laravel answers /api/* (routes/api.php) and makes resized image copies on first request:
Route::get('img/{w}/{path}', [ImageController::class, 'show'])->whereNumber('w')->where('path', '(uploads|assets)/.+');
