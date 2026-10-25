<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ClientIp;
use App\Support\Throttle;
use App\Support\Translations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class I18nController extends Controller
{
    /** The Arabic storefront reports text it found still in English. Always 204, whatever happens. */
    public function missing(Request $r)
    {
        try {
            if (Throttle::attempt('i18n-missing:'.ClientIp::of($r), 30, 3600)) {
                Translations::report('site', $r, (string) $r->header('Referer', ''));
            }
        } catch (\Throwable $e) {
            Log::warning('i18n missing report failed', ['error' => $e->getMessage()]);
        }

        return response()->noContent();
    }

    /** The storefront's translation edits and default language (built-in Arabic ships with the pages). */
    public function overrides(Request $r)
    {
        $data = Translations::overrides('site');
        $json = json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $etag = '"'.sha1($json).'"';
        if ($r->header('If-None-Match') === $etag) {
            return response('', 304, ['ETag' => $etag, 'Cache-Control' => 'public, max-age=60']);
        }

        return response($json, 200, ['Content-Type' => 'application/json; charset=utf-8', 'ETag' => $etag, 'Cache-Control' => 'public, max-age=60']);
    }
}
