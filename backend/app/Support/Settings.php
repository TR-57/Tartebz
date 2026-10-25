<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Key-value settings. Code holds the defaults (config/store_settings.php); saved values are merged over them
 * key by key, so new defaults appear on old installs. Lists are taken whole from what was saved.
 */
class Settings
{
    private static array $memo = [];

    public static function defaults(string $key): mixed
    {
        return config('store_settings.'.$key);
    }

    public static function get(string $key): mixed
    {
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }
        $raw = DB::table('settings')->where('k', $key)->value('v');
        $saved = $raw === null ? null : json_decode($raw, true);
        $default = self::defaults($key);

        return self::$memo[$key] = $saved === null ? $default : self::merge($default, $saved);
    }

    /** The saved value only (no defaults). */
    public static function raw(string $key): mixed
    {
        $raw = DB::table('settings')->where('k', $key)->value('v');

        return $raw === null ? null : json_decode($raw, true);
    }

    public static function set(string $key, mixed $value): void
    {
        DB::table('settings')->upsert(
            [['k' => $key, 'v' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]],
            ['k'],
            ['v', 'updated_at'],
        );
        unset(self::$memo[$key]);
    }

    public static function forget(string $key): void
    {
        DB::table('settings')->where('k', $key)->delete();
        unset(self::$memo[$key]);
    }

    public static function flushMemo(): void
    {
        self::$memo = [];
    }

    public static function merge(mixed $default, mixed $saved): mixed
    {
        if (! is_array($default) || ! is_array($saved)) {
            return $saved;
        }
        // Lists are replaced whole.
        if (array_is_list($saved) || ($default !== [] && array_is_list($default))) {
            return $saved;
        }
        foreach ($saved as $k => $v) {
            $default[$k] = array_key_exists($k, $default) ? self::merge($default[$k], $v) : $v;
        }

        return $default;
    }
}
