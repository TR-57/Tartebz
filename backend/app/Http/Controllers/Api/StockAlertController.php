<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Catalog;
use App\Support\ClientIp;
use App\Support\Settings;
use App\Support\StockAlerts;
use App\Support\Throttle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** POST /api/stock-alerts {product, email | phone, lang, country} – "Tell me when it's back". */
class StockAlertController extends Controller
{
    public function store(Request $r)
    {
        $ip = ClientIp::of($r);
        if (! Throttle::attempt('stock-alert-ip:'.$ip, 15, 3600)) {
            throw new ApiError('too_many', 'Too many requests. Please try again later.', 429);
        }
        if (! (Settings::get('storefront')['backInStock'] ?? true)) {
            throw new ApiError('off', 'Back-in-stock alerts are off.', 403);
        }
        $p = DB::table('products')->where('id', (int) $r->input('product'))->where('status', 'active')->first();
        if (! $p) {
            throw ApiError::notFound('That product is not on sale any more.');
        }
        if (Catalog::available($p)) {
            throw new ApiError('in_stock', 'Good news – it is in stock now.', 409);
        }
        $email = mb_strtolower($this->str($r, 'email', 190));
        $phone = StockAlerts::normalisePhone($this->str($r, 'phone', 30));
        $f = [];
        if ($email === '' && $phone === '') {
            $f['contact'] = 'Enter your email or your WhatsApp number.';
        }
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $f['email'] = 'Enter a valid email.';
        }
        if ($phone !== '' && (strlen($phone) < 8 || strlen($phone) > 15)) {
            $f['phone'] = 'Enter a valid phone number with the country code.';
        }
        if ($f) {
            throw ApiError::validation($f);
        }
        $row = [
            'product_id' => $p->id, 'lang' => $r->input('lang') === 'en' ? 'en' : 'ar',
            'country' => preg_match('/^[A-Z]{2}$/', (string) $r->input('country')) ? $r->input('country') : '',
            'ip' => $ip, 'created_at' => now(),
        ];
        // Asking twice is fine: the same person is kept once per product.
        if ($email !== '') {
            DB::table('stock_alerts')->updateOrInsert(['product_id' => $p->id, 'email' => $email], $row + ['notified_at' => null]);
        }
        if ($phone !== '') {
            DB::table('stock_alerts')->updateOrInsert(['product_id' => $p->id, 'phone' => $phone], $row + ['notified_at' => null]);
        }

        return $this->ok();
    }
}
