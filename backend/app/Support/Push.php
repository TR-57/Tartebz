<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Phone / computer notifications for new orders (Web Push), so the team hears about an order even when the
 * orders board is closed or the screen is asleep. Works in Chrome, Edge, Firefox and Samsung Internet (Android,
 * Windows, Mac) and in Safari on iPhone / iPad once the board is added to the Home Screen (iOS 16.4+).
 *
 * The server's key pair (VAPID) is made on first use and kept in the settings, the private key sealed.
 * Notifications carry only the order number, the customer's first name, the country and the total.
 */
class Push
{
    public static function keys(): array
    {
        $s = Settings::get('push');
        if (($s['publicKey'] ?? '') === '' || Secret::open($s['privateKey'] ?? '') === '') {
            $k = VAPID::createVapidKeys();
            $s = ['publicKey' => $k['publicKey'], 'privateKey' => Secret::seal($k['privateKey'])];
            Settings::set('push', $s);
        }

        return ['publicKey' => $s['publicKey'], 'privateKey' => Secret::open($s['privateKey'])];
    }

    public static function publicKey(): string
    {
        return self::keys()['publicKey'];
    }

    /** Saves (or refreshes) this device's subscription for the signed-in team member. */
    public static function subscribe(object $admin, array $sub, string $device): void
    {
        $endpoint = (string) ($sub['endpoint'] ?? '');
        $p256dh = (string) ($sub['keys']['p256dh'] ?? '');
        $auth = (string) ($sub['keys']['auth'] ?? '');
        if (! preg_match('#^https://[^\s]{10,1000}$#', $endpoint) || ! preg_match('/^[A-Za-z0-9_-]{40,200}$/', $p256dh) || ! preg_match('/^[A-Za-z0-9_-]{10,100}$/', $auth)) {
            throw ApiError::validation(['subscription' => 'This browser sent a notification subscription that cannot be used.']);
        }
        DB::table('push_subscriptions')->updateOrInsert(['endpoint_hash' => hash('sha256', $endpoint)], [
            'admin_id' => $admin->id, 'endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth,
            'device' => mb_substr($device, 0, 120), 'fails' => 0, 'updated_at' => now(), 'created_at' => now(),
        ]);
    }

    public static function unsubscribe(string $endpoint): void
    {
        DB::table('push_subscriptions')->where('endpoint_hash', hash('sha256', $endpoint))->delete();
    }

    /** Tells every active board user's devices about a new order. Never throws: an order must not fail because of a notification. */
    public static function newOrder(int $orderId): int
    {
        try {
            $o = DB::table('orders')->find($orderId);
            if (! $o) {
                return 0;
            }
            $total = $o->charge_currency.' '.number_format((float) $o->charge_amount, in_array($o->charge_currency, ['BHD', 'KWD', 'OMR'], true) ? 3 : 2);
            $whatsapp = $o->payment_method === 'whatsapp' && $o->payment_status !== 'paid';

            return self::send([
                'title' => ($whatsapp ? 'WhatsApp order ' : 'New order ').$o->number.' · '.$total,
                'titleAr' => ($whatsapp ? 'طلب واتساب ' : 'طلب جديد ').$o->number.' · '.$total,
                'body' => trim($o->first_name).' · '.$o->country.($whatsapp ? ' · waiting for payment' : ' · paid'),
                'bodyAr' => trim($o->first_name).' · '.$o->country.($whatsapp ? ' · بانتظار الدفع' : ' · مدفوع'),
                'tag' => 'order-'.$o->id, 'url' => '/staff#order-'.$o->id, 'orderId' => $o->id,
            ]);
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    /** A test notification to one team member's devices. */
    public static function test(object $admin): int
    {
        return self::send(['title' => 'Notifications are on ✓', 'titleAr' => 'التنبيهات تعمل ✓', 'body' => 'You will get one for every new order.',
            'bodyAr' => 'سيصلك تنبيه مع كل طلب جديد.', 'tag' => 'test', 'url' => '/staff'], $admin->id);
    }

    private static function send(array $payload, ?int $adminId = null): int
    {
        $subs = DB::table('push_subscriptions')->join('admins', 'admins.id', '=', 'push_subscriptions.admin_id')
            ->where('admins.active', true)->when($adminId, fn ($q) => $q->where('admins.id', $adminId))
            ->where(fn ($q) => $q->whereIn('admins.role', ['owner', 'manager', 'staff', 'shop_staff']))
            ->select('push_subscriptions.*')->get();
        if ($subs->isEmpty()) {
            return 0;
        }
        $k = self::keys();
        $site = config('store.store_url');
        $push = new WebPush(
            ['VAPID' => ['subject' => $site !== '' && str_starts_with($site, 'https://') ? $site : 'mailto:'.(Settings::get('business')['email'] ?: 'shop@example.com'),
                'publicKey' => $k['publicKey'], 'privateKey' => $k['privateKey']]],
            ['TTL' => 6 * 3600, 'urgency' => 'high', 'topic' => substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($payload['tag'] ?? 'order')), 0, 32)],
            new \GuzzleHttp\Client(['timeout' => 6, 'connect_timeout' => 4]),
            null, null, null, app('log'),
        );
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($subs as $s) {
            $push->queueNotification(Subscription::create(['endpoint' => $s->endpoint, 'contentEncoding' => 'aes128gcm', 'keys' => ['p256dh' => $s->p256dh, 'auth' => $s->auth]]), $json);
        }
        $sent = 0;
        foreach ($push->flush() as $report) {
            $hash = hash('sha256', $report->getEndpoint());
            if ($report->isSuccess()) {
                $sent++;
                DB::table('push_subscriptions')->where('endpoint_hash', $hash)->update(['fails' => 0, 'last_sent_at' => now()]);
            } elseif ($report->isSubscriptionExpired()) {
                DB::table('push_subscriptions')->where('endpoint_hash', $hash)->delete(); // the browser dropped it
            } else {
                DB::table('push_subscriptions')->where('endpoint_hash', $hash)->increment('fails');
                DB::table('push_subscriptions')->where('endpoint_hash', $hash)->where('fails', '>=', 20)->delete();
            }
        }

        return $sent;
    }
}
