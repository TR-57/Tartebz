<?php

namespace App\Support;

use App\Http\Middleware\AdminAuth;
use Illuminate\Http\Request;

/**
 * Maintenance mode. When on:
 *  - every shop page answers 503 with the "back soon" page (Arabic + English), so Google keeps the pages and comes back later.
 *    On Hostinger the web server does this by itself while public_html/maintenance-on.html exists (see .htaccess);
 *    the file is written when maintenance is switched on and deleted when it is switched off.
 *  - placing orders, reserving stock and back-in-stock requests answer "maintenance" (503).
 *  - the admin, the orders board (/staff), payment notifications from Tap and customers' order pages keep working.
 *  - signed-in team members still see and test the whole shop.
 */
class Maintenance
{
    public const FILE = 'maintenance-on.html';

    public static function info(): array
    {
        return Settings::get('maintenance');
    }

    public static function on(): bool
    {
        return (bool) (self::info()['on'] ?? false);
    }

    /** A signed-in, active team member (any role) – they keep seeing the shop. */
    public static function isTeam(Request $r): bool
    {
        return AdminAuth::resolve($r) !== null;
    }

    public static function blocks(Request $r): bool
    {
        return self::on() && ! self::isTeam($r);
    }

    /** {on, message, messageAr} – switches it and writes / removes the page the web server shows. */
    public static function set(bool $on, string $message, string $messageAr, ?object $admin): array
    {
        $was = self::info();
        $value = [
            'on' => $on, 'message' => mb_substr(trim($message), 0, 500), 'messageAr' => mb_substr(trim($messageAr), 0, 500),
            'since' => $on ? ($was['on'] ? $was['since'] : now()->toIso8601String()) : null,
            'by' => $on ? ($was['on'] ? $was['by'] : ($admin->name ?? '')) : '',
        ];
        Settings::set('maintenance', $value);
        self::sync();

        return $value;
    }

    /** Makes the web folder match the setting (also run by deploy.sh and `php artisan store:maintenance`). */
    public static function sync(): bool
    {
        $path = rtrim((string) config('store.public_dir'), '/').'/'.self::FILE;
        if (! self::on()) {
            if (is_file($path)) {
                @unlink($path);
            }

            return true;
        }

        return @file_put_contents($path, self::page()) !== false;
    }

    /** The "back soon" page: one self-contained file, both languages. */
    public static function page(): string
    {
        $m = self::info();
        $brand = Settings::get('brand');
        $biz = Settings::get('business');
        $sf = Settings::get('storefront');
        $logo = '';
        if ($brand['logo']) {
            $logo = Thumbs::url(Media::url($brand['logo'], (int) \Illuminate\Support\Facades\DB::table('media')->where('path', $brand['logo'])->value('version')), 400);
        }
        $wa = preg_replace('/\D/', '', (string) ($sf['whatsappNumber'] ?: ($biz['whatsapp'] ?: $biz['phone'])));

        return view('maintenance', [
            'name' => $brand['name'], 'nameAr' => $brand['name_ar'] ?: $brand['name'], 'logo' => $logo,
            'message' => $m['message'], 'messageAr' => $m['messageAr'], 'whatsapp' => $wa, 'instagram' => $brand['instagram'] ?? '',
        ])->render();
    }

    /** The page as a 503 answer (pages Laravel builds itself, e.g. /product/…). */
    public static function response()
    {
        return response(self::page(), 503, ['Retry-After' => '3600', 'Cache-Control' => 'no-store', 'Content-Type' => 'text/html; charset=utf-8']);
    }
}
