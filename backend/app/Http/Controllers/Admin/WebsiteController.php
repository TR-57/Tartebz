<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Settings;
use App\Support\StockAlerts;
use Illuminate\Http\Request;

/** Website extras: WhatsApp ordering, back-in-stock alerts, payment logos, delivery times (managers). */
class WebsiteController extends Controller
{
    public const LOGOS = ['benefit', 'visa', 'mastercard', 'applepay', 'mada', 'knet', 'tabby', 'cod'];

    public function index()
    {
        return $this->ok(['storefront' => Settings::get('storefront'), 'logos' => self::LOGOS, 'businessWhatsapp' => Settings::get('business')['whatsapp'] ?: Settings::get('business')['phone']]);
    }

    public function save(Request $r)
    {
        $f = [];
        $num = $this->str($r, 'whatsappNumber', 30);
        $num = $num === '' ? '' : StockAlerts::normalisePhone($num);
        if ($num !== '' && (strlen($num) < 8 || strlen($num) > 15)) {
            $f['whatsappNumber'] = 'Enter the WhatsApp number with the country code, e.g. +973 3753 3757.';
        }
        $logos = array_values(array_intersect(self::LOGOS, (array) $r->input('paymentLogos', [])));
        $delivery = [];
        foreach (array_values((array) $r->input('delivery', [])) as $i => $d) {
            $d = (array) $d;
            $cc = strtoupper(trim((string) ($d['country'] ?? '')));
            $min = (int) ($d['min'] ?? 0);
            $max = (int) ($d['max'] ?? 0);
            if (! preg_match('/^[A-Z]{2}$/', $cc)) {
                $f["delivery.$i.country"] = 'A country is a 2-letter code, like SA.';
            }
            if ($min < 0 || $max < $min || $max > 60) {
                $f["delivery.$i.max"] = 'Enter the days as “from” and “to”, with “to” not smaller than “from”.';
            }
            $delivery[] = ['country' => $cc, 'min' => $min, 'max' => $max];
        }
        if ($f) {
            throw ApiError::validation($f);
        }
        $value = [
            'whatsappOrders' => $this->bool($r, 'whatsappOrders', true),
            'whatsappNumber' => $num,
            'backInStock' => $this->bool($r, 'backInStock', true),
            'paymentLogos' => $logos,
            'delivery' => $delivery,
        ];
        Settings::set('storefront', $value);
        Audit::log($r, 'website_settings_saved', 'settings', 'storefront', ['paymentLogos' => $logos, 'whatsappOrders' => $value['whatsappOrders'], 'backInStock' => $value['backInStock']]);

        return $this->ok(['storefront' => Settings::get('storefront')]);
    }
}
