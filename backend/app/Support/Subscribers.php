<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Marketing consent and unsubscribes. Order emails always go; marketing and abandoned-cart emails
 * never go to an address that unsubscribed. The unsubscribe link is signed (no login) and works with one click,
 * including the mail app's own "Unsubscribe" button (List-Unsubscribe + List-Unsubscribe-Post).
 */
class Subscribers
{
    public static function unsubscribed(string $email): bool
    {
        return DB::table('mail_subscribers')->where('email', strtolower(trim($email)))->whereNotNull('unsubscribed_at')->exists();
    }

    /** Records consent. Never re-subscribes someone who unsubscribed unless $again. */
    public static function subscribe(string $email, array $data = [], bool $again = false): void
    {
        $email = strtolower(trim($email));
        $row = DB::table('mail_subscribers')->where('email', $email)->first();
        $vals = array_filter([
            'name' => mb_substr((string) ($data['name'] ?? ''), 0, 120), 'lang' => mb_substr((string) ($data['lang'] ?? ''), 0, 2),
            'country' => mb_substr((string) ($data['country'] ?? ''), 0, 2), 'source' => mb_substr((string) ($data['source'] ?? ''), 0, 20),
        ], fn ($v) => $v !== '');
        if (! $row) {
            DB::table('mail_subscribers')->insert($vals + ['email' => $email, 'consent_at' => now(), 'consent_text' => mb_substr((string) ($data['consent'] ?? ''), 0, 300),
                'created_at' => now(), 'updated_at' => now()]);

            return;
        }
        $upd = $vals + ['updated_at' => now()];
        if ($again || ! $row->unsubscribed_at) {
            $upd += ['consent_at' => now(), 'consent_text' => mb_substr((string) ($data['consent'] ?? ''), 0, 300), 'unsubscribed_at' => null];
        }
        DB::table('mail_subscribers')->where('id', $row->id)->update($upd);
    }

    public static function unsubscribe(string $email): void
    {
        $email = strtolower(trim($email));
        DB::table('mail_subscribers')->upsert([['email' => $email, 'unsubscribed_at' => now(), 'created_at' => now(), 'updated_at' => now()]],
            ['email'], ['unsubscribed_at', 'updated_at']);
        // the abandoned-cart series stops too
        DB::table('carts')->where('email', $email)->whereNull('stopped_at')->update(['stopped_at' => now(), 'stop_reason' => 'unsubscribed']);
    }

    public static function sign(string $email): string
    {
        return substr(hash_hmac('sha256', 'unsub|'.strtolower(trim($email)), (string) config('app.key')), 0, 32);
    }

    public static function check(string $email, string $sig): bool
    {
        return $email !== '' && hash_equals(self::sign($email), $sig);
    }

    public static function url(string $email, string $lang = 'ar'): string
    {
        return config('store.store_url').'/api/email/unsubscribe?e='.rawurlencode(strtolower(trim($email))).'&s='.self::sign($email).'&lang='.$lang;
    }

    /** The headers mail apps use for their own "Unsubscribe" button. */
    public static function headers(string $email): array
    {
        return ['List-Unsubscribe' => '<'.self::url($email).'>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'];
    }

    /** a••••@gmail.com – for logs. */
    public static function mask(string $email): string
    {
        [$u, $d] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($u, 0, 1).'•••'.($d !== '' ? '@'.$d : '');
    }
}
