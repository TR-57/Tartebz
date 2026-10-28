<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Tap Payments (https://developers.tap.company) – API v2.
 * The customer pays on Tap's own page (cards, Apple Pay, Benefit, mada, KNET – whatever the Tap account has on),
 * so card numbers never touch this server. A payment only counts after the charge is fetched again from Tap
 * with the secret key and reads CAPTURED – never on the strength of a redirect or a webhook body.
 */
class Tap
{
    public const BASE = 'https://api.tap.company/v2/';

    /** Tap's API address; TAP_BASE_URL may point elsewhere only for local testing. */
    public static function base(): string
    {
        return rtrim((string) (config('store.tap_base_url') ?: self::BASE), '/').'/';
    }

    /** Tap charge statuses that are final and not a payment. */
    public const FAILED = ['ABANDONED', 'CANCELLED', 'FAILED', 'DECLINED', 'RESTRICTED', 'VOID', 'TIMEDOUT', 'UNKNOWN', 'REVERSED'];

    public static function cfg(): array
    {
        return Settings::get('payments')['tap'];
    }

    public static function mode(): string
    {
        return self::cfg()['mode'] === 'live' ? 'live' : 'test';
    }

    public static function secret(?string $mode = null): string
    {
        $mode ??= self::mode();

        return Secret::open(self::cfg()[$mode === 'live' ? 'liveSecret' : 'testSecret'] ?? '');
    }

    /** Card payment is offered at checkout only when switched on and the key for the current mode is saved. */
    public static function enabled(): bool
    {
        return (bool) self::cfg()['enabled'] && self::secret() !== '';
    }

    /** Checks a key's shape before it is saved: sk_test_… for test, sk_live_… for live. */
    public static function keyProblem(string $key, string $mode): ?string
    {
        if ($key === '') {
            return null;
        }
        $want = $mode === 'live' ? 'sk_live_' : 'sk_test_';
        if (! str_starts_with($key, $want)) {
            return "This does not look like a {$mode} secret key – it should start with {$want}.";
        }

        return null;
    }

    public static function request(string $method, string $path, ?array $body = null, ?string $mode = null): array
    {
        $key = self::secret($mode);
        if ($key === '') {
            throw new ApiError('tap_not_set', 'Tap is not set up: save the secret key for '.($mode ?? self::mode()).' mode in Payments.', 409);
        }
        try {
            $req = Http::withToken($key)->acceptJson()->timeout(30)->connectTimeout(10);
            $res = $body === null ? $req->send($method, self::base().ltrim($path, '/')) : $req->send($method, self::base().ltrim($path, '/'), ['json' => $body]);
        } catch (ConnectionException) {
            throw new ApiError('tap_unreachable', 'Could not reach Tap. Try again in a moment.', 502);
        }
        $json = $res->json() ?? [];
        if ($res->status() === 401 || $res->status() === 403) {
            throw new ApiError('tap_auth', 'Tap refused the key (wrong key, or a test key in live mode / live key in test mode).', 502);
        }
        if (! $res->successful()) {
            $msg = $json['errors'][0]['description'] ?? $json['message'] ?? ('Tap answered '.$res->status().'.');
            throw new ApiError('tap_error', 'Tap: '.$msg, 502);
        }

        return $json;
    }

    /** Opens a charge for an order. Returns Tap's charge (id, status, transaction.url). */
    public static function createCharge(object $order): array
    {
        $site = config('store.store_url');
        [$cc, $num] = Shipping::splitPhone($order->phone, $order->country);
        $cfg = self::cfg();
        $body = [
            'amount' => (float) $order->charge_amount,
            'currency' => $order->charge_currency,
            'threeDSecure' => true,
            'save_card' => false,
            'customer_initiated' => true,
            'description' => 'Tartebz order '.$order->number,
            'statement_descriptor' => 'Tartebz',
            'metadata' => ['udf1' => $order->number],
            'reference' => ['transaction' => 'txn_'.$order->number.'_'.substr(md5((string) microtime(true)), 0, 6), 'order' => (string) $order->number],
            'receipt' => ['email' => $order->email !== '', 'sms' => false],
            'customer' => array_filter([
                'first_name' => $order->first_name,
                'last_name' => $order->last_name ?: null,
                'email' => $order->email ?: null,
                'phone' => ['country_code' => $cc, 'number' => $num],
            ]),
            'source' => ['id' => 'src_all'],
            'post' => ['url' => $site.'/api/payments/tap/webhook'],
            'redirect' => ['url' => $site.'/checkout?order='.$order->token.($order->lang === 'en' ? '&lang=en' : '')],
        ];
        if (($cfg['merchantId'] ?? '') !== '') {
            $body['merchant'] = ['id' => $cfg['merchantId']];
        }

        return self::request('POST', 'charges', $body);
    }

    public static function retrieve(string $chargeId, ?string $mode = null): array
    {
        if (! preg_match('/^chg_[A-Za-z0-9]+$/', $chargeId)) {
            throw new ApiError('tap_bad_id', 'That is not a Tap charge id.', 422);
        }

        return self::request('GET', 'charges/'.$chargeId, null, $mode);
    }

    /**
     * Gives money back on a captured charge (all or part). $amount is in the currency the customer paid.
     * Tap answers with the refund (id re_…, status PENDING / IN_PROGRESS / REFUNDED / FAILED …) and later posts
     * the final status to the webhook.
     */
    public static function refund(object $order, float $amount, string $reason, int $refundRowId): array
    {
        return self::request('POST', 'refunds', [
            'charge_id' => $order->tap_charge_id,
            'amount' => $amount,
            'currency' => $order->charge_currency,
            'reason' => mb_substr($reason !== '' ? $reason : 'requested_by_customer', 0, 255),
            'reference' => ['merchant' => 'rf_'.$order->number.'_'.$refundRowId],
            'metadata' => ['udf1' => $order->number, 'udf2' => (string) $refundRowId],
            'post' => ['url' => config('store.store_url').'/api/payments/tap/webhook'],
        ], $order->tap_mode ?: null);
    }

    public static function retrieveRefund(string $refundId, ?string $mode = null): array
    {
        if (! preg_match('/^re_[A-Za-z0-9]+$/', $refundId)) {
            throw new ApiError('tap_bad_id', 'That is not a Tap refund id.', 422);
        }

        return self::request('GET', 'refunds/'.$refundId, null, $mode);
    }

    /** Tap refund status → ours: done | failed | pending. */
    public static function refundState(string $status): string
    {
        $s = strtoupper($status);

        return match (true) {
            in_array($s, ['REFUNDED', 'SUCCEEDED', 'CAPTURED'], true) => 'done',
            in_array($s, ['FAILED', 'CANCELLED', 'DECLINED', 'REJECTED', 'VOID', 'REVERSED'], true) => 'failed',
            default => 'pending',
        };
    }

    /**
     * Checks the saved key for a mode without charging anything: asks Tap for a charge that does not exist.
     * A wrong key answers 401; a right key answers "not found".
     */
    public static function test(string $mode): array
    {
        try {
            self::request('GET', 'charges/chg_TZconnectioncheck0000000000', null, $mode);

            return ['connected' => true, 'message' => 'Connected to Tap.'];
        } catch (ApiError $e) {
            if ($e->errCode === 'tap_error') {
                return ['connected' => true, 'message' => 'Connected to Tap – the key is accepted.'];
            }

            return ['connected' => false, 'message' => $e->getMessage()];
        }
    }

    /** Tap's webhook signature (header "hashstring"). Informational: the charge is always fetched again anyway. */
    public static function signature(array $p, string $secret): string
    {
        $cur = (string) ($p['currency'] ?? '');
        $dec = in_array($cur, ['BHD', 'KWD', 'OMR', 'JOD'], true) ? 3 : 2;
        $amount = number_format((float) ($p['amount'] ?? 0), $dec, '.', '');
        $s = 'x_id'.($p['id'] ?? '').'x_amount'.$amount.'x_currency'.$cur
            .'x_gateway_reference'.($p['reference']['gateway'] ?? '').'x_payment_reference'.($p['reference']['payment'] ?? '')
            .'x_status'.($p['status'] ?? '').'x_created'.($p['transaction']['created'] ?? '');

        return hash_hmac('sha256', $s, $secret);
    }
}
