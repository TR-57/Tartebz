<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The client's IP. Proxy headers are believed only when TRUST_PROXY is on and the immediate peer is private
 * (the web server or Cloudflare restores the real address in front of us).
 */
class ClientIp
{
    public static function of(Request $r): string
    {
        $peer = (string) $r->server('REMOTE_ADDR', '');
        if (! config('store.trust_proxy') || ! self::isPrivate($peer)) {
            return $peer;
        }
        $cf = trim((string) $r->header('CF-Connecting-IP', ''));
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }
        $xff = array_reverse(array_map('trim', explode(',', (string) $r->header('X-Forwarded-For', ''))));
        foreach ($xff as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) && ! self::isPrivate($ip)) {
                return $ip;
            }
        }

        return $peer;
    }

    public static function isPrivate(string $ip): bool
    {
        if ($ip === '') {
            return true;
        }

        return ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
