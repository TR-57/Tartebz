<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The one HTML layout every email uses. 600px, tables and inline styles (Gmail, Outlook, Apple Mail), a hidden pre-header,
 * the shop's own logo and colours (read from the website's CSS variables, with safe fallbacks), right-to-left for Arabic,
 * a dark-mode style for mail apps that support it, and a footer with the shop's name, address, phone, social links and website.
 */
class EmailLayout
{
    /** The website's look: brand (buttons, header), text, page background, card, radius, font. */
    public static function theme(): array
    {
        return Cache::remember('email:theme', 600, function () {
            $css = @file_get_contents(rtrim((string) config('store.root'), '/').'/assets/css/style.css') ?: '';
            $var = function (string $name, string $fallback) use ($css) {
                return preg_match('/--'.preg_quote($name, '/').':\s*([^;]+);/', $css, $m) ? trim($m[1]) : $fallback;
            };
            $hex = fn (string $v, string $fb) => preg_match('/^#[0-9a-f]{3,8}$/i', $v) ? $v : $fb;
            $radius = (int) preg_replace('/\D/', '', $var('radius', '18px')) ?: 18;

            return [
                'brand' => $hex($var('red', '#E30613'), '#E30613'),
                'accent' => $hex($var('yellow', '#FFD700'), '#FFD700'),
                'text' => $hex($var('ink', '#1F2024'), '#1F2024'),
                'muted' => $hex($var('gray', '#5F636B'), '#5F636B'),
                'line' => $hex($var('line', '#E7E7E7'), '#E7E7E7'),
                'bg' => $hex($var('cream-2', '#FFFBEB'), '#FFFBEB'),
                'card' => '#FFFFFF',
                'radius' => min(24, $radius),
                // the site font first (where the mail app has it), then safe fallbacks
                'font' => "'Alexandria', 'Segoe UI', Tahoma, Helvetica, Arial, sans-serif",
            ];
        });
    }

    /** Full https address for a site path. */
    public static function abs(string $url): string
    {
        if ($url === '' || preg_match('#^https?://#i', $url)) {
            return $url;
        }

        return rtrim((string) config('store.store_url'), '/').'/'.ltrim($url, '/');
    }

    /** The shop's logo as a PNG/JPEG address mail apps can show (the uploaded logo, else the site icon). */
    public static function logo(): string
    {
        $brand = Settings::get('brand');
        if (! empty($brand['logo'])) {
            $ver = (int) DB::table('media')->where('path', $brand['logo'])->value('version');

            return self::abs(Thumbs::url(Media::url($brand['logo'], $ver), 200));
        }

        return self::abs('/assets/img/logo-icon-192.png');
    }

    /**
     * $opts: unsubscribe (url: shown in the footer – marketing and cart emails), title (big heading above the body).
     */
    public static function wrap(string $lang, string $preheader, string $body, array $opts = []): string
    {
        $t = self::theme();
        $ar = $lang === 'ar';
        $dir = $ar ? 'rtl' : 'ltr';
        $align = $ar ? 'right' : 'left';
        $font = $ar ? "'Alexandria', Tahoma, 'Segoe UI', Arial, sans-serif" : $t['font'];
        $brand = Settings::get('brand');
        $b = Settings::get('business');
        $storeName = e($ar ? ($brand['name_ar'] ?: $brand['name']) : $brand['name']);
        $site = config('store.store_url');
        $siteLabel = e(preg_replace('#^https?://#', '', $site));
        $logo = e(self::logo());
        $pre = e($preheader);
        $r = $t['radius'];

        $address = e(trim(implode(', ', array_filter([
            $ar ? ($b['addressLine1_ar'] ?: $b['addressLine1']) : $b['addressLine1'], $b['addressLine2'] ?? '', $b['area'] ?? '',
            $ar ? 'مملكة البحرين' : 'Kingdom of Bahrain',
        ]))));
        $phone = trim((string) ($b['phone'] ?? ''));
        $wa = preg_replace('/\D/', '', (string) (Settings::get('storefront')['whatsappNumber'] ?: ($b['whatsapp'] ?: '')));
        $links = [];
        if ($brand['instagram'] ?? '') {
            $links[] = '<a href="'.e($brand['instagram']).'" style="color:'.$t['accent'].';text-decoration:none;">Instagram</a>';
        }
        if ($brand['tiktok'] ?? '') {
            $links[] = '<a href="'.e($brand['tiktok']).'" style="color:'.$t['accent'].';text-decoration:none;">TikTok</a>';
        }
        if ($wa !== '') {
            $links[] = '<a href="https://wa.me/'.$wa.'" style="color:'.$t['accent'].';text-decoration:none;">WhatsApp</a>';
        }
        $links[] = '<a href="'.e($site).'" style="color:'.$t['accent'].';text-decoration:none;">'.$siteLabel.'</a>';
        $linksHtml = implode(' &nbsp;·&nbsp; ', $links);
        $legal = e(trim(($b['legalName'] ?? '').(($b['cr'] ?? '') ? ' · '.($ar ? 'س.ت ' : 'CR ').$b['cr'] : '').(($b['vat'] ?? '') ? ' · '.($ar ? 'الرقم الضريبي ' : 'VAT ').$b['vat'] : '')));
        $help = $phone !== ''
            ? ($ar ? 'عندك سؤال؟ رد على هذه الرسالة أو اتصل بنا على <bdi dir="ltr">'.e($phone).'</bdi>' : 'Questions? Reply to this email or call us on <bdi dir="ltr">'.e($phone).'</bdi>')
            : ($ar ? 'عندك سؤال؟ رد على هذه الرسالة.' : 'Questions? Just reply to this email.');
        $unsub = ! empty($opts['unsubscribe'])
            ? '<br><a href="'.e($opts['unsubscribe']).'" style="color:#c9cbd1;text-decoration:underline;">'.($ar ? 'إلغاء الاشتراك في الرسائل' : 'Unsubscribe').'</a>'
            : '';
        $title = ! empty($opts['title']) ? '<h1 class="em-text" style="margin:0 0 14px;font-size:24px;line-height:1.3;color:'.$t['text'].';">'.e($opts['title']).'</h1>' : '';

        return <<<HTML
<!doctype html>
<html lang="{$lang}" dir="{$dir}" xmlns="http://www.w3.org/1999/xhtml"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark"><title>{$storeName}</title>
<style>
  body{margin:0;padding:0;-webkit-text-size-adjust:100%;}
  a{color:{$t['brand']};}
  @media (prefers-color-scheme: dark){
    .em-bg{background:#121214 !important;} .em-card{background:#1c1d21 !important;}
    .em-text{color:#EDEDEF !important;} .em-muted{color:#B5B7BE !important;} .em-line{border-color:#33353b !important;}
    .em-box{background:#26272c !important;border-color:#3a3c42 !important;}
  }
  @media (max-width:620px){ .em-pad{padding:22px 18px !important;} .em-h1{font-size:22px !important;} }
</style></head>
<body class="em-bg" style="margin:0;padding:0;background:{$t['bg']};">
<div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;mso-hide:all;">{$pre}&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="em-bg" style="background:{$t['bg']};"><tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" class="em-card" style="width:100%;max-width:600px;background:{$t['card']};border-radius:{$r}px;overflow:hidden;font-family:{$font};color:{$t['text']};" dir="{$dir}">
<tr><td style="background:{$t['brand']};padding:18px 28px;border-bottom:4px solid {$t['accent']};text-align:{$align};">
  <a href="{$site}" style="text-decoration:none;color:#ffffff;display:inline-block;"><img src="{$logo}" alt="{$storeName}" width="48" height="48" style="vertical-align:middle;border:0;border-radius:50%;background:#ffffff;">
  <span style="font-size:22px;font-weight:800;color:#ffffff;vertical-align:middle;padding:0 10px;">{$storeName}</span></a></td></tr>
<tr><td class="em-pad em-text" style="padding:30px 28px;font-size:16px;line-height:1.7;text-align:{$align};color:{$t['text']};">{$title}{$body}</td></tr>
<tr><td class="em-muted" style="padding:0 28px 24px;font-size:14px;line-height:1.6;color:{$t['muted']};text-align:{$align};">{$help}</td></tr>
<tr><td style="background:{$t['text']};color:#c9cbd1;padding:18px 28px;font-size:13px;line-height:1.7;text-align:{$align};">
  <b style="color:#ffffff;">{$storeName}</b><br>{$address}<br>{$legal}<br>{$linksHtml}{$unsub}</td></tr>
</table></td></tr></table></body></html>
HTML;
    }

    /** The email's main button. */
    public static function button(string $label, string $url, bool $secondary = false): string
    {
        $t = self::theme();
        $l = e($label);
        $u = e($url);
        $style = $secondary
            ? "display:inline-block;background:#ffffff;color:{$t['brand']};border:2px solid {$t['brand']};text-decoration:none;font-weight:700;font-size:16px;padding:10px 24px;border-radius:999px;"
            : "display:inline-block;background:{$t['brand']};color:#ffffff;text-decoration:none;font-weight:700;font-size:16px;padding:13px 30px;border-radius:999px;";

        return "<p style=\"margin:22px 0;\"><a href=\"{$u}\" style=\"{$style}\">{$l}</a></p>";
    }

    /** A soft box for notes (how to pay, the code …). */
    public static function box(string $html, bool $big = false): string
    {
        $t = self::theme();
        $pad = $big ? '18px 20px' : '14px 18px';

        return "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"margin:16px 0;\"><tr><td class=\"em-box em-text\" style=\"background:#FFF8DC;border:1px solid #F3E3A3;border-radius:12px;padding:{$pad};font-size:15px;line-height:1.6;color:{$t['text']};\">{$html}</td></tr></table>";
    }
}
