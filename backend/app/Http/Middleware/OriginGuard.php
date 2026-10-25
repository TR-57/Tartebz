<?php

namespace App\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;

/**
 * CSRF protection for the JSON API: every state-changing request must come from one of our own origins
 * (the store, the admin, or the request's own host). Origin header first, else the Referer's origin.
 * Webhooks are exempt (config store.origin_exempt) and verified by their own checks.
 */
class OriginGuard
{
    public function handle(Request $request, Closure $next)
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }
        foreach (config('store.origin_exempt') as $pattern) {
            if ($request->is($pattern)) {
                return $next($request);
            }
        }
        $origin = (string) $request->header('Origin', '');
        if ($origin === '' || $origin === 'null') {
            $ref = (string) $request->header('Referer', '');
            $origin = $ref !== '' ? self::originOf($ref) : '';
        }
        if ($origin === '' || ! in_array(strtolower($origin), self::allowed($request), true)) {
            throw new ApiError('bad_origin', 'This request did not come from our website.', 403);
        }

        return $next($request);
    }

    public static function allowed(Request $r): array
    {
        $list = [self::originOf(config('store.store_url')), self::originOf(config('store.admin_url')), strtolower($r->getSchemeAndHttpHost())];

        return array_values(array_unique(array_filter($list)));
    }

    public static function originOf(string $url): string
    {
        $p = parse_url($url);
        if (empty($p['scheme']) || empty($p['host'])) {
            return '';
        }

        return strtolower($p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : ''));
    }
}
