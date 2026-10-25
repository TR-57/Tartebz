<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Sessions for customers ("user") and staff ("admin").
 * The cookie holds 32 random bytes in hex; the database stores only its SHA-256, so a leaked backup cannot be replayed.
 * Admin sessions last 12 hours from sign-in (no sliding). Customer sessions last 30 days and slide.
 */
class AuthSessions
{
    public const ADMIN = 'admin';

    public const USER = 'user';

    public static function cookieName(string $type): string
    {
        $p = config('store.cookie_prefix');

        return $type === self::ADMIN ? $p.'_adm' : $p.'_sid';
    }

    /** Starts a session and returns the raw token for the cookie. */
    public static function start(string $type, int $subjectId, Request $r): string
    {
        $token = bin2hex(random_bytes(32));
        $now = now();
        $expires = $type === self::ADMIN
            ? $now->copy()->addHours(config('store.admin_session_hours'))
            : $now->copy()->addDays(config('store.customer_session_days'));
        DB::table('sessions')->insert([
            'id' => hash('sha256', $token),
            'subject_type' => $type,
            'subject_id' => $subjectId,
            'ip' => ClientIp::of($r),
            'user_agent' => mb_substr((string) $r->userAgent(), 0, 255),
            'created_at' => $now,
            'last_seen_at' => $now,
            'expires_at' => $expires,
        ]);

        return $token;
    }

    /** The live session row for the request's cookie, or null. Touches last_seen at most every 5 minutes. */
    public static function current(string $type, Request $r): ?object
    {
        $token = (string) $r->cookie(self::cookieName($type), '');
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $row = DB::table('sessions')
            ->where('id', hash('sha256', $token))
            ->where('subject_type', $type)
            ->where('expires_at', '>', now())
            ->first();
        if (! $row) {
            return null;
        }
        if (strtotime($row->last_seen_at) < time() - 300) {
            $upd = ['last_seen_at' => now()];
            if ($type === self::USER) {
                $upd['expires_at'] = now()->addDays(config('store.customer_session_days'));
            }
            DB::table('sessions')->where('id', $row->id)->update($upd);
        }

        return $row;
    }

    public static function end(string $type, Request $r): void
    {
        $token = (string) $r->cookie(self::cookieName($type), '');
        if ($token !== '') {
            DB::table('sessions')->where('id', hash('sha256', $token))->delete();
        }
    }

    /** Signs the person out everywhere (password changed, account disabled). */
    public static function endAll(string $type, int $subjectId): void
    {
        DB::table('sessions')->where('subject_type', $type)->where('subject_id', $subjectId)->delete();
    }

    public static function purgeExpired(): int
    {
        return DB::table('sessions')->where('expires_at', '<', now())->delete();
    }

    /** Admin cookie: HttpOnly, Secure, SameSite=Lax, host-only (never shared with the parent domain). */
    public static function cookie(string $type, string $token): Cookie
    {
        $minutes = $type === self::ADMIN
            ? config('store.admin_session_hours') * 60
            : config('store.customer_session_days') * 1440;
        $domain = $type === self::ADMIN ? null : config('store.cookie_domain');

        return cookie(self::cookieName($type), $token, $minutes, '/', $domain, config('store.cookie_secure'), true, false, 'lax');
    }

    public static function forgetCookie(string $type): Cookie
    {
        $domain = $type === self::ADMIN ? null : config('store.cookie_domain');

        return cookie(self::cookieName($type), '', -2628000, '/', $domain, config('store.cookie_secure'), true, false, 'lax');
    }
}
