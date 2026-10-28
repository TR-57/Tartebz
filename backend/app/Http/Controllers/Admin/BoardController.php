<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Catalog;
use App\Support\Checkout;
use App\Support\EmailLayout;
use App\Support\Holds;
use App\Support\Media;
use App\Support\Orders;
use App\Support\Outbox;
use App\Support\Push;
use App\Support\Settings;
use App\Support\Shipping;
use App\Support\Tap;
use App\Support\Thumbs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The orders board (/staff) for shop staff and the rest of the team:
 *  - create an order for a customer who ordered on WhatsApp (prices, delivery by weight and country, stock: all from the server)
 *  - send the customer a card payment link (WhatsApp or email)
 *  - print the invoice
 *  - new-order check for the sound, and phone / computer notifications
 */
class BoardController extends Controller
{
    /** Products the team can put in an order, with what is free to sell right now. */
    public function products()
    {
        $tree = Catalog::categoryTree();
        $live = array_keys(array_filter($tree, fn ($c) => $c->live));
        $rows = DB::table('products')->where('status', 'active')->whereIn('category_id', $live ?: [0])->orderBy('sort_rank')->orderByDesc('id')->get();
        $held = Holds::heldAll($rows->pluck('id')->all());

        return $this->ok(['products' => $rows->map(function ($p) use ($tree, $held) {
            $images = json_decode((string) $p->images, true) ?: [];
            $counted = Holds::counted($p);

            return [
                'id' => $p->id, 'name' => $p->name, 'nameAr' => $p->name_ar ?: $p->name, 'price' => (float) $p->price,
                'image' => $images ? Thumbs::url($images[0], 200) : null, 'weightGrams' => Catalog::weightGrams($p, $tree),
                'available' => Catalog::available($p), 'free' => $counted ? max(0, (int) $p->stock_qty - ($held[$p->id] ?? 0)) : null,
            ];
        })->values(), 'countries' => $this->countries(), 'tap' => Tap::enabled()]);
    }

    /** {items, country} – the same prices and delivery fee the website charges. */
    public function quote(Request $r)
    {
        $country = strtoupper($this->str($r, 'country', 2));
        if (! Shipping::delivers($country)) {
            throw ApiError::validation(['country' => 'We do not deliver to that country.']);
        }

        return $this->ok(['quote' => CheckoutController::present(Checkout::quote((array) $r->input('items', []), $country))]);
    }

    /**
     * Creates an order for a customer. {items, country, first_name, last_name?, phone, email?, city, state?, address1, address2?,
     * notes?, lang, payment: link|paid|later, paid_how? (with payment=paid), expected_total?}
     *   link  – the customer pays by card with a payment link (sent from the next screen)
     *   paid  – the money is already in (Benefit transfer, cash …): the order is paid at once
     *   later – the customer pays later (WhatsApp); mark it paid when the money arrives
     */
    public function store(Request $r)
    {
        $admin = $this->admin($r);
        $payment = in_array($r->input('payment'), ['link', 'paid', 'later'], true) ? $r->input('payment') : '';
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
        if (mb_strlen($c['first_name']) < 2) {
            $f['first_name'] = 'Enter the customer’s name.';
        }
        [$phone, $phoneErr] = \App\Support\Phone::check($c['phone'], $country);
        if ($phoneErr) {
            $f['phone'] = $c['phone'] === '' ? 'Enter the customer’s phone number.' : $phoneErr;
        }
        if ($c['email'] !== '' && ! filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
            $f['email'] = 'This email does not look right (or leave it empty).';
        }
        if (! Shipping::delivers($country)) {
            $f['country'] = 'Choose the delivery country.';
        }
        if (mb_strlen($c['city']) < 2) {
            $f['city'] = 'Enter the city or area.';
        }
        if (mb_strlen($c['address1']) < 2) {
            $f['address1'] = 'Enter the address (house, road, block).';
        }
        if ($payment === '') {
            $f['payment'] = 'Choose how the customer pays.';
        }
        $how = $this->str($r, 'paid_how', 120);
        if ($payment === 'paid' && $how === '') {
            $f['paid_how'] = 'Say how it was paid (e.g. Benefit transfer, cash).';
        }
        if ($payment === 'link' && ! Tap::enabled()) {
            $f['payment'] = 'Card payment links need Tap to be switched on (Settings › Payments).';
        }
        if ($f) {
            throw ApiError::validation($f);
        }
        $c['phone'] = $phone; // saved with its country code, so WhatsApp and SMSA can use it

        $items = (array) $r->input('items', []);
        $seen = round((float) $r->input('expected_total', -1), 3);
        $method = $payment === 'link' ? 'tap' : 'whatsapp';
        $result = DB::transaction(function () use ($items, $country, $seen, $c, $method, $admin, $r) {
            $ids = array_values(array_filter(array_map(fn ($i) => (int) (((array) $i)['id'] ?? 0), $items)));
            DB::table('products')->whereIn('id', $ids ?: [0])->orderBy('id')->lockForUpdate()->get(['id']);
            $quote = Checkout::quote($items, $country);
            if (! $quote['lines']) {
                throw ApiError::validation(['items' => 'Add at least one product that is in stock.']);
            }
            if ($quote['issues'] || ($seen >= 0 && abs($seen - $quote['total']) > 0.0005)) {
                return ['changed' => $quote];
            }
            $order = Orders::create($quote, $c, $method, ['ip' => '', 'ua' => (string) $r->userAgent(), 'source' => 'staff', 'admin' => $admin]);
            Holds::toOrder(null, $order, $quote['lines']);

            return ['order' => $order];
        });
        if (isset($result['changed'])) {
            return response()->json(['ok' => false, 'error' => ['code' => 'changed', 'message' => 'Stock or prices changed. Check the order again.'],
                'quote' => CheckoutController::present($result['changed'])], 409);
        }
        $o = $result['order'];
        if ($payment === 'paid') {
            DB::transaction(function () use ($o, $how, $admin) {
                $locked = DB::table('orders')->where('id', $o->id)->lockForUpdate()->first();
                Orders::markPaid($locked, 'Paid (recorded when the order was created): '.$how.'.', [], $admin);
            });
        }
        Audit::log($r, 'order_created', 'order', $o->id, ['number' => $o->number, 'payment' => $payment, 'total' => (float) $o->total]);
        $o = DB::table('orders')->find($o->id);

        return $this->ok(['order' => $this->summary($o)] + ($payment === 'link' ? $this->links($o) : []));
    }

    /** {via: whatsapp|email} – the card payment link for an unpaid order. A WhatsApp order switches to card payment. */
    public function paymentLink(Request $r, int $id)
    {
        $o = DB::table('orders')->find($id) ?? throw ApiError::notFound('That order does not exist.');
        if ($o->paid_at || in_array($o->status, ['cancelled', 'refunded'], true)) {
            throw new ApiError('not_payable', $o->paid_at ? 'This order is already paid.' : 'This order is cancelled.', 409);
        }
        if (! Tap::enabled()) {
            throw new ApiError('tap_off', 'Card payment links need Tap to be switched on (Settings › Payments).', 409);
        }
        if ($o->payment_method !== 'tap') {
            DB::table('orders')->where('id', $id)->update(['payment_method' => 'tap', 'status' => 'pending_payment', 'payment_status' => 'unpaid', 'updated_at' => now()]);
            $o = DB::table('orders')->find($id);
        }
        $via = $r->input('via') === 'email' ? 'email' : 'whatsapp';
        $links = $this->links($o);
        if ($via === 'email') {
            if ($o->email === '') {
                throw ApiError::validation(['email' => 'This order has no email. Send the link on WhatsApp.']);
            }
            $this->emailLink($o, $links['payUrl']);
        }
        Orders::event($id, 'payment_link', 'Payment link sent by '.($via === 'email' ? 'email' : 'WhatsApp').'.', [], $this->admin($r));

        return $this->ok($links + ['sent' => $via]);
    }

    /** GET – a printable invoice (A4 or receipt printer). ?lang=ar|en (default: the customer's language). */
    public function invoice(Request $r, int $id)
    {
        $o = DB::table('orders')->find($id) ?? throw ApiError::notFound('That order does not exist.');
        $lang = in_array($r->query('lang'), ['ar', 'en'], true) ? $r->query('lang') : ($o->lang === 'en' ? 'en' : 'ar');
        $brand = Settings::get('brand');
        $biz = Settings::get('business');
        $logo = $brand['logo'] ? Media::url($brand['logo'], (int) DB::table('media')->where('path', $brand['logo'])->value('version')) : '';
        $dec = in_array($o->charge_currency, ['BHD', 'KWD', 'OMR', 'JOD'], true) ? 3 : 2;
        $rate = $o->charge_currency === 'BHD' ? 1 : (float) $o->fx_rate;
        $money = fn (float $bhd) => $o->charge_currency.' '.number_format(round($bhd * $rate, $dec), $dec);

        return response()->view('invoice', [
            'o' => $o, 'ar' => $lang === 'ar', 'lang' => $lang, 'money' => $money,
            'items' => DB::table('order_items')->where('order_id', $o->id)->orderBy('id')->get(),
            'shop' => [
                'name' => $lang === 'ar' ? ($brand['name_ar'] ?: $brand['name']) : $brand['name'], 'legal' => $biz['legalName'], 'cr' => $biz['cr'], 'vat' => $biz['vat'],
                'address' => $lang === 'ar' ? ($biz['addressLine1_ar'] ?: $biz['addressLine1']) : $biz['addressLine1'], 'area' => $biz['area'],
                'phone' => $biz['phone'], 'email' => $biz['email'], 'logo' => $logo ? Thumbs::url($logo, 400) : '',
            ],
            'refunded' => (float) $o->refunded_amount,
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * GET ?since=ISO – orders that need the team since the last check (for the sound), and the counts for the tabs.
     * The board asks every 15 seconds; the answer is tiny.
     */
    public function pulse(Request $r)
    {
        $since = (string) $r->query('since', '');
        $new = [];
        if ($since !== '' && strtotime($since)) {
            $new = DB::table('orders')->whereNotNull('alert_at')->where('alert_at', '>=', date('Y-m-d H:i:s', strtotime($since)))
                ->orderBy('alert_at')->limit(20)->get()->map(fn ($o) => [
                    'id' => $o->id, 'number' => $o->number, 'name' => trim($o->first_name.' '.$o->last_name), 'country' => $o->country,
                    'method' => $o->payment_method, 'paid' => (bool) $o->paid_at,
                    'total' => $o->charge_currency.' '.number_format((float) $o->charge_amount, in_array($o->charge_currency, ['BHD', 'KWD', 'OMR'], true) ? 3 : 2),
                ])->values()->all();
        }

        return $this->ok([
            'now' => now()->toIso8601String(), 'new' => $new,
            'counts' => [
                'prepare' => DB::table('orders')->whereIn('status', ['paid', 'processing'])->whereNull('prepared_at')->count(),
                'unpaid' => DB::table('orders')->whereIn('status', ['pending_payment', 'awaiting_whatsapp', 'payment_failed'])->count(),
            ],
        ]);
    }

    public function pushKey()
    {
        return $this->ok(['publicKey' => Push::publicKey()]);
    }

    /** {subscription: {endpoint, keys: {p256dh, auth}}, device?} */
    public function pushSubscribe(Request $r)
    {
        Push::subscribe($this->admin($r), (array) $r->input('subscription', []), $this->str($r, 'device', 120));

        return $this->ok();
    }

    public function pushUnsubscribe(Request $r)
    {
        Push::unsubscribe((string) $r->input('endpoint', ''));

        return $this->ok();
    }

    public function pushTest(Request $r)
    {
        return $this->ok(['sent' => Push::test($this->admin($r))]);
    }

    private function countries(): array
    {
        $sh = Settings::get('shipping');

        return array_values(array_map(fn ($c) => ['code' => $c, 'dial' => Shipping::DIAL[$c] ?? ''], (array) $sh['countries']));
    }

    private function summary(object $o): array
    {
        return [
            'id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'paymentStatus' => $o->payment_status, 'method' => $o->payment_method,
            'name' => trim($o->first_name.' '.$o->last_name), 'phone' => $o->phone, 'email' => $o->email, 'country' => $o->country,
            'total' => (float) $o->total, 'chargeCurrency' => $o->charge_currency, 'chargeAmount' => (float) $o->charge_amount,
        ];
    }

    /** The customer's payment page and a ready WhatsApp message to them. */
    private function links(object $o): array
    {
        $url = Orders::customerUrl($o);
        $brand = Settings::get('brand');
        $ar = $o->lang !== 'en';
        $total = $o->charge_currency.' '.number_format((float) $o->charge_amount, in_array($o->charge_currency, ['BHD', 'KWD', 'OMR'], true) ? 3 : 2);
        $shop = $ar ? ($brand['name_ar'] ?: $brand['name']) : $brand['name'];
        $text = $ar
            ? "مرحبا {$o->first_name}، هذا رابط الدفع لطلبك رقم {$o->number} من {$shop} ({$total}):\n{$url}"
            : "Hi {$o->first_name}, here is the payment link for your {$shop} order {$o->number} ({$total}):\n{$url}";
        [$cc, $num] = Shipping::splitPhone($o->phone, $o->country);

        return ['payUrl' => $url, 'whatsappUrl' => 'https://wa.me/'.$cc.$num.'?text='.rawurlencode($text), 'message' => $text];
    }

    private function emailLink(object $o, string $url): void
    {
        $ar = $o->lang !== 'en';
        $total = e($o->charge_currency.' '.number_format((float) $o->charge_amount, in_array($o->charge_currency, ['BHD', 'KWD', 'OMR'], true) ? 3 : 2));
        $subject = $ar ? "رابط الدفع لطلبك رقم {$o->number}" : "Pay for your order {$o->number}";
        $body = ($ar
            ? '<h1 style="margin:0 0 10px;font-size:22px;">مرحبا '.e($o->first_name).'</h1><p>طلبك رقم <b>'.e($o->number).'</b> جاهز. المبلغ: <b><bdi>'.$total.'</bdi></b>. اضغط الزر للدفع بشكل آمن.</p>'
            : '<h1 style="margin:0 0 10px;font-size:22px;">Hi '.e($o->first_name).'</h1><p>Your order <b>'.e($o->number).'</b> is ready. Amount: <b>'.$total.'</b>. Press the button to pay securely.</p>')
            .EmailLayout::button($ar ? 'ادفع الآن' : 'Pay now', $url);
        Outbox::queue($o->email, $subject, EmailLayout::wrap($ar ? 'ar' : 'en', $subject, $body), ['name' => trim($o->first_name.' '.$o->last_name)]);
    }
}
