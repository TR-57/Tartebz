<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The client's IP. Proxy headers are believed only when TRUST_PROXY is on and the immediate peer is private
 * (the web server or Cloudflare restores the real address in front of us).
 */
class ClientIp
{
    /** Cloudflare's published ranges (https://www.cloudflare.com/ips/). Only these may tell us the visitor's address. */
    public const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public static function of(Request $r): string
    {
        $peer = (string) $r->server('REMOTE_ADDR', '');
        $mode = config('store.trust_proxy');
        if ($mode === 'cloudflare') {
            $cf = trim((string) $r->header('CF-Connecting-IP', ''));

            return $cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) && self::inRanges($peer, self::CLOUDFLARE) ? $cf : $peer;
        }
        if (! $mode || ! self::isPrivate($peer)) {
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

    public static function inRanges(string $ip, array $cidrs): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach ($cidrs as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $n = @inet_pton($net);
            if ($n === false || strlen($n) !== strlen($bin)) {
                continue;
            }
            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);
            if (substr($bin, 0, $bytes) !== substr($n, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0 || ((ord($bin[$bytes]) ^ ord($n[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return true;
            }
        }

        return false;
    }

    public static function isPrivate(string $ip): bool
    {
        if ($ip === '') {
            return true;
        }

        return ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
