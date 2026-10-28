<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Server health checks for Hostinger (php artisan store:doctor, run at the end of every deploy).
 * Each check gives: ok | warn | fail, what was checked, and how to fix it in hPanel.
 */
class Doctor
{
    /** The scheduled tasks ran in the last 15 minutes (store:sync-payments notes each run). */
    public static function cronRecent(): bool
    {
        $last = Cache::get('cron:last-run');

        return $last && now()->diffInMinutes(\Illuminate\Support\Carbon::parse($last), true) <= 15;
    }

    /** @return array<int, array{0: string, 1: string, 2: string}> [status, check, fix] */
    public static function run(): array
    {
        $out = [];
        $add = function (string $status, string $check, string $fix = '') use (&$out) {
            $out[] = [$status, $check, $fix];
        };

        // PHP
        $add(version_compare(PHP_VERSION, '8.2.0', '>=') ? (version_compare(PHP_VERSION, '8.3.0', '>=') ? 'ok' : 'warn') : 'fail',
            'PHP '.PHP_VERSION, 'hPanel › Advanced › PHP Configuration › choose PHP 8.3.');
        foreach (['pdo_mysql', 'mbstring', 'openssl', 'curl', 'fileinfo', 'gd', 'tokenizer', 'ctype', 'xml'] as $ext) {
            $add(extension_loaded($ext) ? 'ok' : 'fail', 'PHP extension '.$ext, 'hPanel › Advanced › PHP Configuration › PHP extensions › tick '.$ext.'.');
        }
        $webp = function_exists('gd_info') && (gd_info()['WebP Support'] ?? false);
        $add($webp ? 'ok' : 'warn', 'Pictures can be saved as WebP', 'Without it, resized photos are larger. Ask Hostinger support to enable WebP in GD.');
        $mem = self::bytes((string) ini_get('memory_limit'));
        $add($mem < 0 || $mem >= 256 * 1024 * 1024 ? 'ok' : 'warn', 'Memory limit '.ini_get('memory_limit'), 'PHP Configuration › PHP options › memory_limit 256M (large photos need it).');
        $up = min(self::bytes((string) ini_get('upload_max_filesize')), self::bytes((string) ini_get('post_max_size')));
        $add($up >= (int) config('store.upload_max_mb') * 1024 * 1024 ? 'ok' : 'warn', 'Uploads up to '.ini_get('upload_max_filesize'),
            'PHP options › upload_max_filesize and post_max_size at least '.config('store.upload_max_mb').'M.');

        // App settings
        $add(config('app.key') ? 'ok' : 'fail', 'APP_KEY is set', 'Run: php artisan key:generate (once – changing it later locks saved keys).');
        $add(config('app.debug') ? 'fail' : 'ok', 'Debug mode is off', 'APP_DEBUG=false in backend/.env (deploy.sh sets it).');
        $url = (string) config('store.store_url');
        $add(str_starts_with($url, 'https://') && ! str_contains($url, 'example.com') ? 'ok' : 'fail', 'STORE_URL '.$url,
            'STORE_URL=https://your-domain in backend/.env. Tap and SMSA send customers and notices back to this address.');

        // Database
        try {
            DB::select('select 1');
            $add('ok', 'Database connection');
            $pending = self::pendingMigrations();
            $add($pending ? 'fail' : 'ok', $pending ? $pending.' database update(s) not applied' : 'Database is up to date', 'Run: php artisan migrate --force');
        } catch (Throwable $e) {
            $add('fail', 'Database connection', 'Check DB_HOST / DB_DATABASE / DB_USERNAME / DB_PASSWORD in backend/.env (hPanel › Databases).');
        }

        // Folders
        $dirs = ['storage' => storage_path(), 'bootstrap/cache' => base_path('bootstrap/cache'), 'labels' => storage_path('app/labels'),
            'uploads' => config('store.upload_dir'), 'img' => config('store.thumb_dir')];
        foreach ($dirs as $name => $dir) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $add(is_dir($dir) && is_writable($dir) ? 'ok' : 'fail', 'Folder '.$name.' can be written', 'File Manager › '.$dir.' › Permissions 755.');
        }
        $free = @disk_free_space(storage_path());
        if ($free !== false) {
            $add($free > 500 * 1024 * 1024 ? 'ok' : 'warn', 'Free disk space '.round($free / 1024 / 1024).' MB', 'Delete old backups in hPanel › Files, or move to a bigger plan.');
        }
        $pub = (string) config('store.public_dir');
        $add(is_file($pub.'/api.php') && is_file($pub.'/.htaccess') ? 'ok' : 'warn', 'Website folder has api.php and .htaccess', 'Run: bash deploy/hostinger/deploy.sh');

        // Background jobs
        $add(self::cronRecent() ? 'ok' : 'warn', 'Scheduled tasks ran in the last 15 minutes',
            'hPanel › Advanced › Cron Jobs › every minute: '.PHP_BINARY.' '.base_path('artisan').' schedule:run');

        // Services
        $add(Outbox::configured() ? 'ok' : 'warn', 'Email sending (SMTP)', 'Admin › Settings › Email (Hostinger mailbox: smtp.hostinger.com, port 465).');
        if (Tap::enabled()) {
            $add(Tap::mode() === 'live' ? 'ok' : 'warn', 'Card payments (Tap) in '.Tap::mode().' mode', 'Admin › Settings › Payments – switch to live after a test order.');
        } else {
            $add('warn', 'Card payments (Tap) are off', 'Admin › Settings › Payments – paste the keys, test, switch on.');
        }
        $add(Smsa::enabled() ? 'ok' : 'warn', 'SMSA shipping', 'Admin › Settings › Shipping – paste the SMSA key and sender address.');
        if (config('store.tap_base_url')) {
            $add('fail', 'TAP_BASE_URL is set (local testing only)', 'Remove TAP_BASE_URL from backend/.env.');
        }

        return $out;
    }

    private static function pendingMigrations(): int
    {
        $files = array_map(fn ($f) => basename($f, '.php'), glob(database_path('migrations/*.php')) ?: []);
        $ran = DB::table('migrations')->pluck('migration')->all();

        return count(array_diff($files, $ran));
    }

    private static function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '-1') {
            return -1;
        }
        $n = (int) $v;

        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n,
        };
    }
}
