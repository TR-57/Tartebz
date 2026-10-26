<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Thumbs;

/**
 * GET /img/<width>/<uploads|assets>/<path>  – the resized copy, made on first request.
 * On Hostinger the .htaccess serves an existing copy straight from disk, so this only runs once per size.
 * If a copy cannot be made, the browser is sent to the original: a picture never goes missing.
 */
class ImageController extends Controller
{
    public function show(int $w, string $path)
    {
        $file = Thumbs::make($w, $path);
        if (! $file) {
            $orig = '/'.ltrim($path, '/');

            return redirect(Thumbs::source($path) ? $orig : '/assets/img/favicon.svg', 302)->header('Cache-Control', 'public, max-age=300');
        }

        return response()->file($file, ['Content-Type' => 'image/webp', 'Cache-Control' => 'public, max-age=2592000']);
    }

    /** GET /img/og/<uploads|assets>/<path> – 1200×630 JPEG for link previews. */
    public function og(string $path)
    {
        $file = Thumbs::makeOg($path);
        if (! $file) {
            return redirect(Thumbs::source($path) ? '/'.ltrim($path, '/') : '/assets/img/favicon.svg', 302);
        }

        return response()->file($file, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=2592000']);
    }
}
