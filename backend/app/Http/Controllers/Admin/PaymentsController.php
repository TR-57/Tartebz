<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Currency;
use App\Support\Secret;
use App\Support\Settings;
use App\Support\Tap;
use Illuminate\Http\Request;

/** Owner: Tap keys (test and live), test/live switch, connection test; WhatsApp ordering on/off. Keys are never sent back. */
class PaymentsController extends Controller
{
    public function index()
    {
        return $this->ok($this->present());
    }

    public function save(Request $r)
    {
        $p = Settings::get('payments');
        $tap = $p['tap'];
        $f = [];
        foreach (['test', 'live'] as $mode) {
            foreach (['Secret', 'Publishable'] as $kind) {
                $k = $mode.$kind;
                if ($this->bool($r, 'clear'.ucfirst($k))) {
                    $tap[$k] = '';

                    continue;
                }
                $v = Tap::cleanKey((string) $r->input($k, ''));
                if ($v === '') {
                    continue; // empty = keep the saved key
                }
                if ($why = Tap::keyProblem($v, $mode, $kind === 'Secret' ? 'sk' : 'pk')) {
                    $f[$k] = $why;
                }
                $tap[$k] = $kind === 'Secret' ? Secret::seal($v) : $v;
            }
        }
        $mode = $r->input('mode') === 'live' ? 'live' : 'test';
        $enabled = $this->bool($r, 'enabled', (bool) $tap['enabled']);
        if ($enabled && Secret::open($tap[$mode.'Secret']) === '') {
            $f[$mode.'Secret'] = 'Save the '.$mode.' secret key before switching card payments on in '.$mode.' mode.';
        }
        $curs = array_values(array_intersect(array_keys(Currency::enabled()), (array) $r->input('chargeCurrencies', $tap['chargeCurrencies'])));
        if (! in_array('BHD', $curs, true)) {
            array_unshift($curs, 'BHD');
        }
        // Merchant ID from the Tap dashboard (e.g. merchant_… or a number). Sent with every charge when set.
        $merchant = Tap::cleanKey((string) $r->input('merchantId', ''));
        if ($merchant !== '' && ! preg_match('/^[A-Za-z0-9_-]{3,64}$/', $merchant)) {
            $f['merchantId'] = 'A Tap Merchant ID has only letters, numbers and _ (for example merchant_… or 1234567).';
        }
        if ($f) {
            throw ApiError::validation($f);
        }
        $was = ['enabled' => $tap['enabled'], 'mode' => $tap['mode']];
        $tap['enabled'] = $enabled;
        $tap['mode'] = $mode;
        $tap['merchantId'] = $merchant;
        $tap['chargeCurrencies'] = $curs;
        $p['tap'] = $tap;
        $p['whatsapp'] = ['enabled' => $this->bool($r, 'whatsappEnabled', (bool) ($p['whatsapp']['enabled'] ?? true))];
        Settings::set('payments', $p);
        Audit::log($r, 'payments_saved', 'settings', 'payments', ['from' => $was, 'to' => ['enabled' => $enabled, 'mode' => $mode], 'chargeCurrencies' => $curs]);

        return $this->ok($this->present());
    }

    public function test(Request $r)
    {
        $mode = $r->input('mode') === 'live' ? 'live' : 'test';
        $typed = Tap::cleanKey((string) $r->input('key', ''));   // a key typed but not saved yet: test it as it is
        $res = Tap::test($mode, $typed !== '' ? $typed : null);
        if ($typed !== '' && $res['connected']) {
            $res['message'] .= ' Press Save to keep this key.';
        }
        Audit::log($r, 'payments_tested', 'settings', 'payments', ['mode' => $mode, 'connected' => $res['connected'], 'unsaved' => $typed !== '']);

        return $this->ok($res);
    }

    /** Apple Pay domain verification file (text from Apple / Tap), or {clear: true}. */
    public function applePayFile(Request $r)
    {
        $p = Settings::get('payments');
        if ($this->bool($r, 'clear')) {
            $p['applePay'] = ['domainFile' => '', 'uploadedAt' => null];
        } else {
            $file = $r->file('file');
            $text = $file ? (string) file_get_contents($file->getRealPath()) : (string) $r->input('text', '');
            $text = trim($text);
            // Apple's file is a long line of hex / base64-like text (a few KB). Anything else is refused.
            if ($text === '' || strlen($text) > 20000 || ! preg_match('/^[A-Za-z0-9+\/=\s{}":,._-]+$/', $text)) {
                throw ApiError::validation(['file' => 'This is not an Apple Pay domain file. Upload the file named apple-developer-merchantid-domain-association exactly as you received it.']);
            }
            $p['applePay'] = ['domainFile' => $text, 'uploadedAt' => now()->toIso8601String()];
        }
        Settings::set('payments', $p);
        Audit::log($r, 'applepay_file', 'settings', 'payments', ['set' => $p['applePay']['domainFile'] !== '']);

        return $this->ok($this->present());
    }

    private function present(): array
    {
        $p = Settings::get('payments');
        $t = $p['tap'];
        $mask = fn ($v) => ['set' => $v !== '', 'last4' => strlen($v) >= 8 ? substr($v, -4) : ''];

        return [
            'tap' => [
                'enabled' => (bool) $t['enabled'], 'mode' => $t['mode'], 'merchantId' => $t['merchantId'],
                'testSecret' => Secret::describe($t['testSecret']), 'liveSecret' => Secret::describe($t['liveSecret']),
                'testPublishable' => $mask($t['testPublishable']), 'livePublishable' => $mask($t['livePublishable']),
                'chargeCurrencies' => $t['chargeCurrencies'], 'active' => Tap::enabled(),
            ],
            'whatsappEnabled' => (bool) ($p['whatsapp']['enabled'] ?? true),
            'currencies' => array_keys(Currency::enabled()),
            'webhookUrl' => config('store.store_url').'/api/payments/tap/webhook',
            'returnUrl' => config('store.store_url').'/checkout',
            'applePay' => [
                'fileSet' => ($p['applePay']['domainFile'] ?? '') !== '', 'uploadedAt' => $p['applePay']['uploadedAt'] ?? null,
                'url' => config('store.store_url').'/.well-known/apple-developer-merchantid-domain-association',
                'domain' => parse_url((string) config('store.store_url'), PHP_URL_HOST),
            ],
        ];
    }
}
