<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Media;
use App\Support\Settings;
use App\Support\Thumbs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Owners: the shop's own details – name, logo, legal name, CR and VAT numbers, address, phone, WhatsApp, email,
 * social links and time zone. They show on the website (header, footer, contact page), in every
 * email and on invoices, so they are changed here and nowhere else.
 */
class BusinessController extends Controller
{
    /** Text fields: key => max length. */
    private const BIZ = [
        'legalName' => 190, 'cr' => 60, 'vat' => 60, 'addressLine1' => 255, 'addressLine1_ar' => 255, 'addressLine2' => 255,
        'area' => 120, 'phone' => 30, 'whatsapp' => 30, 'email' => 190,
    ];

    public function index()
    {
        return $this->ok(self::present());
    }

    public static function present(): array
    {
        $b = Settings::get('business');
        $brand = Settings::get('brand');
        $logo = $brand['logo'] ? Media::url($brand['logo'], (int) DB::table('media')->where('path', $brand['logo'])->value('version')) : '';
        $biz = [];
        foreach (array_keys(self::BIZ) as $k) {
            $biz[$k] = (string) ($b[$k] ?? '');
        }

        return [
            'business' => $biz + ['timezone' => (string) ($b['timezone'] ?? 'Asia/Bahrain')],
            'brand' => ['name' => (string) $brand['name'], 'name_ar' => (string) $brand['name_ar'], 'instagram' => (string) $brand['instagram'],
                'tiktok' => (string) $brand['tiktok'], 'mapsUrl' => (string) $brand['mapsUrl'], 'logo' => (string) $brand['logo'],
                'logoUrl' => $logo ? Thumbs::url($logo, 400) : ''],
            'timezones' => array_values(array_filter(\DateTimeZone::listIdentifiers(), fn ($z) => str_starts_with($z, 'Asia/') || str_starts_with($z, 'Europe/') || $z === 'UTC')),
        ];
    }

    public function save(Request $r)
    {
        $f = [];
        $biz = Settings::get('business');
        foreach (self::BIZ as $k => $max) {
            if ($r->has($k)) {
                $biz[$k] = trim($this->str($r, $k, $max));
            }
        }
        $biz['email'] = strtolower($biz['email'] ?? '');
        if ($biz['email'] === '' || ! filter_var($biz['email'], FILTER_VALIDATE_EMAIL)) {
            $f['email'] = 'Enter the shop’s email address.';
        }
        foreach (['phone', 'whatsapp'] as $k) {
            $v = (string) ($biz[$k] ?? '');
            if ($v !== '' && ! preg_match('/^\+?[0-9 ()-]{7,20}$/', $v)) {
                $f[$k] = 'Enter the number with the country code, e.g. +973 3753 3757.';
            }
        }
        if (($biz['phone'] ?? '') === '') {
            $f['phone'] = 'Enter the shop’s phone number.';
        }
        if ($r->has('timezone')) {
            $tz = $this->str($r, 'timezone', 60);
            if (! in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                $f['timezone'] = 'Choose a time zone.';
            }
            $biz['timezone'] = $tz;
        }

        $brand = Settings::get('brand');
        foreach (['name' => 80, 'name_ar' => 80] as $k => $max) {
            if ($r->has($k)) {
                $brand[$k] = trim($this->str($r, $k, $max));
            }
        }
        if (($brand['name'] ?? '') === '') {
            $f['name'] = 'Enter the shop name.';
        }
        foreach (['instagram', 'tiktok', 'mapsUrl'] as $k) {
            if (! $r->has($k)) {
                continue;
            }
            $v = trim($this->str($r, $k, 300));
            if ($v !== '' && ! preg_match('#^https://[^\s]+$#i', $v)) {
                $f[$k] = 'Paste the full link, starting with https://';
            }
            $brand[$k] = $v;
        }
        if ($r->has('logo')) {
            $logo = $this->str($r, 'logo', 255);
            if ($logo !== '' && ! DB::table('media')->where('path', $logo)->where('kind', 'image')->exists()) {
                $f['logo'] = 'Choose a picture from the media library.';
            }
            $brand['logo'] = $logo;
        }
        if ($f) {
            throw ApiError::validation($f);
        }
        Settings::set('business', $biz);
        Settings::set('brand', $brand);
        Cache::forget('email:theme');
        Audit::log($r, 'business_saved', 'settings', 'business', ['name' => $brand['name'], 'email' => $biz['email'], 'phone' => $biz['phone']]);

        return $this->ok(self::present());
    }
}
