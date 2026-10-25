<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Fixed-window counters in the cache.
 *   Throttle::tooMany('login-ip:1.2.3.4', 20, 900)  – check without counting
 *   Throttle::hit('login-ip:1.2.3.4', 900)           – count one attempt
 *   Throttle::attempt('x', 30, 3600)                  – count, and say whether it is still allowed
 */
class Throttle
{
    private static function bucket(string $key, int $seconds): string
    {
        return 'thr:'.sha1($key).':'.$seconds.':'.intdiv(time(), max(1, $seconds));
    }

    public static function count(string $key, int $seconds): int
    {
        return (int) Cache::get(self::bucket($key, $seconds), 0);
    }

    public static function tooMany(string $key, int $max, int $seconds): bool
    {
        return self::count($key, $seconds) >= $max;
    }

    public static function hit(string $key, int $seconds): int
    {
        $b = self::bucket($key, $seconds);
        Cache::add($b, 0, $seconds + 5);

        return (int) Cache::increment($b);
    }

    /** Counts this attempt; true when it is within the limit. */
    public static function attempt(string $key, int $max, int $seconds): bool
    {
        return self::hit($key, $seconds) <= $max;
    }

    public static function clear(string $key, int $seconds): void
    {
        Cache::forget(self::bucket($key, $seconds));
    }
}
