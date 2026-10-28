<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Checkout;
use App\Support\Holds;
use App\Support\ClientIp;
use App\Support\Orders;
use App\Support\Settings;
use App\Support\Shipping;
use App\Support\Tap;
use App\Support\Throttle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Checkout. The browser sends product ids, quantities, the delivery country and the customer's details –
 * every price, the delivery charge and the total are worked out here.
 */
class CheckoutController extends Controller
{
    /** POST /api/checkout/quote {items, country} */
    public function quote(Request $r)
    {
        $cart = self::cartKey($r);
        $country = strtoupper($this->str($r, 'country', 2));
        if (! Shipping::delivers($country)) {
            throw ApiError::validation(['country' => 'We do not deliver to that country yet.']);
        }

        return $this->ok(['quote' => self::present(Checkout::quote((array) $r->input('items', []), $country, $cart)), 'payments' => self::methods()]);
    }

    /** POST /api/checkout {items, country, method: tap|whatsapp, first_name, …} */
    public function place(Request $r)
    {
        $ip = ClientIp::of($r);
        if (! Throttle::attempt('checkout-ip:'.$ip, 20, 3600)) {
            throw new ApiError('too_many', 'Too many orders from this connection. Please try again later or order on WhatsApp.', 429);
        }
        $method = $this->str($r, 'method', 20);
        $methods = self::methods();
        if (! ($methods[$method] ?? false)) {
            throw ApiError::validation(['method' => 'Choose how you want to pay.']);
        }
        $c = [
            'first_name' => $this->str($r, 'first_name', 80), 'last_name' => $this->str($r, 'last_name', 80),
            'email' => mb_strtolower($this->str($r, 'email', 190)), 'phone' => $this->str($r, 'phone', 30),
            'state' => $this->str($r, 'state', 80), 'city' => $this->str($r, 'city', 80),
            'address1' => $this->str($r, 'address1', 200), 'address2' => $this->str($r, 'address2', 200),
            'notes' => mb_substr(trim((string) $r->input('notes', '')), 0, 1000),
            'lang' => $r->input('lang') === 'en' ? 'en' : 'ar',
        ];
        $country = strtoupper($this->str($r, 'country', 2));
        $f = [];
        foreach (['first_name' => 'Enter your first name.', 'last_name' => 'Enter your last name.', 'address1' => 'Enter your street / area.', 'city' => 'Enter your city.'] as $k => $msg) {
            if (mb_strlen($c[$k]) < 2) {
                $f[$k] = $msg;
            }
        }
        // Email: needed for card payments (receipt, confirmation); optional when ordering on WhatsApp.
        if ($c['email'] !== '' || $method === 'tap') {
            if (! filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
                $f['email'] = 'Enter a valid email.';
            }
        }
        [$phone, $phoneErr] = \App\Support\Phone::check($c['phone'], strtoupper($this->str($r, 'country', 2)));
        if ($phoneErr) {
            $f['phone'] = $phoneErr;
        } else {
            $c['phone'] = $phone;
        }
        if (! Shipping::delivers($country)) {
            $f['country'] = 'We do not deliver to that country yet.';
        }
        if ($f) {
            throw ApiError::validation($f);
        }
        $cart = self::cartKey($r);
        $items = (array) $r->input('items', []);
        $seen = round((float) $r->input('expected_total', -1), 3);
        // One request at a time per product: lock the rows, price the cart against what is free, create the order
        // and move the cart's reservations to it – all before anyone else can take the same pieces.
        $result = DB::transaction(function () use ($items, $country, $cart, $seen, $c, $method, $ip, $r) {
            $ids = array_values(array_filter(array_map(fn ($i) => (int) (((array) $i)['id'] ?? 0), $items)));
            DB::table('products')->whereIn('id', $ids ?: [0])->orderBy('id')->lockForUpdate()->get(['id']);
            $quote = Checkout::quote($items, $country, $cart);
            if (! $quote['lines']) {
                throw new ApiError('empty', 'Your cart is empty or its products are sold out.', 409);
            }
            // Prices or stock changed since the customer looked: show them the new figures before taking money.
            if ($quote['issues'] || ($seen >= 0 && abs($seen - $quote['total']) > 0.0005)) {
                return ['changed' => $quote];
            }
            $order = Orders::create($quote, $c, $method, ['ip' => $ip, 'ua' => (string) $r->userAgent()]);
            Holds::toOrder($cart, $order, $quote['lines']);

            return ['order' => $order];
        });
        if (isset($result['changed'])) {
            return response()->json(['ok' => false, 'error' => ['code' => 'changed', 'message' => 'Your cart changed (price or stock). Please check the new total.'],
                'quote' => self::present($result['changed'])], 409);
        }
        $order = $result['order'];

        if ($method === 'whatsapp') {
            return $this->ok(['order' => $this->publicOrder($order), 'whatsapp' => $this->whatsappLink($order)]);
        }

        return $this->ok(['order' => $this->publicOrder($order), 'redirect' => $this->openCharge($order)]);
    }

    /** GET /api/orders/{token} – the customer's order page; checks Tap again while the payment is pending. */
    public function show(string $token)
    {
        $o = $this->byToken($token);
        if ($o->tap_charge_id && $o->payment_status !== 'paid' && in_array($o->status, ['pending_payment', 'payment_failed'], true)
            && Cache::add('tap-check:'.$o->id, 1, 4)) {
            try {
                $o = Orders::applyTapCharge($o->id, Tap::retrieve($o->tap_charge_id, $o->tap_mode));
            } catch (ApiError) {
                // Tap unreachable: show what we know; the webhook and the sync job will catch up.
            }
        }

        return $this->ok(['order' => $this->publicOrder($o)]);
    }

    /** POST /api/orders/{token}/pay – try the card payment again after a failed or abandoned attempt. */
    public function pay(Request $r, string $token)
    {
        if (! Throttle::attempt('pay-again:'.ClientIp::of($r), 15, 3600)) {
            throw new ApiError('too_many', 'Too many attempts. Please try again later.', 429);
        }
        $o = $this->byToken($token);
        if ($o->payment_status === 'paid' || ! in_array($o->status, ['pending_payment', 'payment_failed'], true)) {
            throw new ApiError('not_payable', 'This order cannot be paid again.', 409);
        }
        if (! Tap::enabled()) {
            throw new ApiError('tap_off', 'Card payment is not available right now. Please order on WhatsApp.', 409);
        }
        // Left Tap's page without finishing: send them back to the same payment (never a second charge while
        // the first one can still be paid). Tap's page stays open 30 minutes.
        if ($o->status === 'pending_payment' && $o->tap_charge_id) {
            try {
                $charge = Tap::retrieve($o->tap_charge_id, $o->tap_mode ?: null);
            } catch (ApiError) {
                throw new ApiError('payment_unavailable', 'The payment page could not be opened. Please try again in a moment.', 502);
            }
            $o = Orders::applyTapCharge($o->id, $charge);
            if ($o->paid_at) {
                return $this->ok(['paid' => true]);
            }
            $created = (int) (($charge['transaction']['created'] ?? 0) / 1000) ?: strtotime((string) $o->updated_at);
            $url = (string) ($charge['transaction']['url'] ?? '');
            if ($o->status === 'pending_payment' && $url !== '' && time() - $created < 29 * 60) {
                DB::transaction(fn () => Holds::renewOrder($o));

                return $this->ok(['redirect' => $url]);
            }
            if ($o->status === 'pending_payment' && time() - $created < 30 * 60) {
                throw new ApiError('payment_open', 'Your payment is still open. Please try again in a minute.', 409);
            }
        }
        // Keep (or take back) the order's pieces; if someone else has them by now, say so before taking money.
        if (! DB::transaction(fn () => Holds::renewOrder($o))) {
            throw new ApiError('changed', 'Some products in this order are sold out now. Please place a new order.', 409);
        }
        DB::table('orders')->where('id', $o->id)->update(['status' => 'pending_payment', 'payment_status' => 'unpaid', 'updated_at' => now()]);

        return $this->ok(['redirect' => $this->openCharge(DB::table('orders')->find($o->id))]);
    }

    /** POST /api/cart/hold {cart, items} – reserves the cart's products for this customer for a few minutes. */
    public function hold(Request $r)
    {
        $ip = ClientIp::of($r);
        if (! Throttle::attempt('cart-hold:'.$ip, 600, 3600)) {
            throw new ApiError('too_many', 'Too many requests. Please try again later.', 429);
        }
        $cart = self::cartKey($r);
        if (! $cart) {
            throw ApiError::validation(['cart' => 'Missing cart.']);
        }

        return $this->ok(Holds::syncCart($cart, (array) $r->input('items', []), $ip));
    }

    /** The browser's random cart id (32 hex characters). */
    public static function cartKey(Request $r): ?string
    {
        $k = (string) $r->input('cart', '');

        return preg_match('/^[a-f0-9]{32}$/', $k) ? $k : null;
    }

    /** Which ways to pay the checkout offers now. */
    public static function methods(): array
    {
        return ['tap' => Tap::enabled(), 'whatsapp' => (bool) (Settings::get('payments')['whatsapp']['enabled'] ?? true)];
    }

    private function openCharge(object $order): string
    {
        try {
            $charge = Tap::createCharge($order);
        } catch (ApiError $e) {
            Orders::event($order->id, 'payment_error', 'Could not open the Tap payment page: '.$e->getMessage());
            throw new ApiError('payment_unavailable', 'The payment page could not be opened. Please try again, or order on WhatsApp.', 502);
        }
        DB::table('orders')->where('id', $order->id)->update([
            'tap_charge_id' => (string) $charge['id'], 'tap_status' => (string) ($charge['status'] ?? ''), 'tap_mode' => Tap::mode(), 'updated_at' => now(),
        ]);
        Orders::event($order->id, 'payment_started', 'Sent to the Tap payment page ('.Tap::mode().' mode).', ['charge' => $charge['id']]);
        $url = (string) ($charge['transaction']['url'] ?? '');
        // Customers are only ever sent to an https page (plain http is allowed only for the local Tap stand-in).
        if (! str_starts_with($url, 'https://') && ! (config('store.tap_base_url') && str_starts_with($url, 'http://localhost'))) {
            throw new ApiError('payment_unavailable', 'The payment page could not be opened. Please try again, or order on WhatsApp.', 502);
        }

        return $url;
    }

    private function byToken(string $token): object
    {
        if (! preg_match('/^[a-f0-9]{40}$/', $token)) {
            throw ApiError::notFound('Order not found.');
        }

        return DB::table('orders')->where('token', $token)->first() ?? throw ApiError::notFound('Order not found.');
    }

    private function whatsappLink(object $o): string
    {
        $ar = $o->lang !== 'en';
        $lines = [$ar ? "مرحبا ترتيبز، أبغى أكمل طلبي رقم {$o->number}:" : "Hi Tartebz, I’d like to complete my order {$o->number}:", ''];
        foreach (DB::table('order_items')->where('order_id', $o->id)->get() as $i) {
            $lines[] = '• '.($ar && $i->name_ar ? $i->name_ar : $i->name).' × '.$i->qty;
        }
        $lines[] = '';
        $lines[] = ($ar ? 'الإجمالي: ' : 'Total: ').$o->charge_currency.' '.number_format((float) $o->charge_amount, in_array($o->charge_currency, ['BHD', 'KWD', 'OMR'], true) ? 3 : 2);
        $lines[] = trim($o->first_name.' '.$o->last_name).' · '.$o->phone;
        $lines[] = implode('، ', array_filter([$o->country, $o->state, $o->city, $o->address1, $o->address2]));
        $num = Settings::get('storefront')['whatsappNumber'] ?: preg_replace('/\D/', '', Settings::get('business')['whatsapp'] ?: Settings::get('business')['phone']);

        return 'https://wa.me/'.$num.'?text='.rawurlencode(implode("\n", $lines));
    }

    public static function present(array $q): array
    {
        return [
            'lines' => $q['lines'], 'issues' => $q['issues'], 'subtotal' => $q['subtotal'], 'shipping' => $q['shipping'], 'total' => $q['total'],
            'weightGrams' => $q['weightGrams'], 'country' => $q['country'], 'currency' => $q['currency'], 'rate' => $q['rate'],
            'chargeCurrency' => $q['chargeCurrency'], 'chargeAmount' => $q['chargeAmount'],
        ];
    }

    /** What the customer may see about their order (no internal notes, no IP). */
    private function publicOrder(object $o): array
    {
        $items = DB::table('order_items')->where('order_id', $o->id)->get();

        return [
            'number' => $o->number, 'token' => $o->token, 'status' => $o->status, 'paymentStatus' => $o->payment_status, 'method' => $o->payment_method,
            'subtotal' => (float) $o->subtotal, 'shipping' => (float) $o->shipping, 'total' => (float) $o->total,
            'currency' => $o->currency, 'rate' => (float) $o->fx_rate, 'chargeCurrency' => $o->charge_currency, 'chargeAmount' => (float) $o->charge_amount,
            'firstName' => $o->first_name, 'country' => $o->country, 'city' => $o->city, 'email' => $o->email,
            'awb' => $o->awb, 'trackingUrl' => $o->awb ? 'https://www.smsaexpress.com/trackingdetails?tracknumbers='.rawurlencode($o->awb) : null,
            'testMode' => $o->tap_mode === 'test', 'hasCharge' => $o->tap_charge_id !== '',
            'heldUntil' => $o->payment_status === 'paid' ? null : Holds::orderHeldUntil($o->id),
            'canRetry' => $o->payment_method === 'tap' && $o->payment_status !== 'paid' && in_array($o->status, ['pending_payment', 'payment_failed'], true),
            'items' => $items->map(fn ($i) => ['productId' => $i->product_id, 'name' => $i->name, 'nameAr' => $i->name_ar, 'qty' => (int) $i->qty, 'lineTotal' => (float) $i->line_total])->values(),
            'createdAt' => $o->created_at,
        ];
    }
}
