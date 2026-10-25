<?php

namespace App\Support;

/**
 * The HTML shell every email uses: table layout, inline styles, RTL for Arabic, hidden pre-header,
 * header bar with the logo, body, contact line and the company footer.
 */
class EmailLayout
{
    public static function wrap(string $lang, string $preheader, string $body): string
    {
        $ar = $lang === 'ar';
        $dir = $ar ? 'rtl' : 'ltr';
        $align = $ar ? 'right' : 'left';
        $font = $ar ? "Tahoma, 'Segoe UI', Arial, sans-serif" : "'Segoe UI', Helvetica, Arial, sans-serif";
        $b = Settings::get('business');
        $store = e(config('store.name'));
        $site = config('store.store_url');
        $phone = e($b['phone'] ?? '');
        $q = $ar ? "أسئلة؟ رد على هذه الرسالة أو اتصل بنا على <bdi dir=\"ltr\">{$phone}</bdi>" : "Questions? Reply to this email or call us on {$phone}";
        $footer = e(($b['legalName'] ?? '').' · CR '.($b['cr'] ?? '').(($b['vat'] ?? '') ? ' · VAT '.$b['vat'] : '').' · '.($b['area'] ?? '').', '.($ar ? 'مملكة البحرين' : 'Kingdom of Bahrain'));
        $pre = e($preheader);

        return <<<HTML
<!doctype html>
<html lang="{$lang}" dir="{$dir}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{$store}</title></head>
<body style="margin:0;padding:0;background:#f5f1e6;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{$pre}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f1e6;"><tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;font-family:{$font};color:#1f2024;" dir="{$dir}">
<tr><td style="background:#E30613;padding:20px 28px;border-bottom:4px solid #FFD700;text-align:{$align};"><a href="{$site}" style="color:#ffffff;text-decoration:none;font-size:24px;font-weight:800;">{$store}</a></td></tr>
<tr><td style="padding:28px;font-size:15px;line-height:1.7;text-align:{$align};">{$body}</td></tr>
<tr><td style="padding:0 28px 22px;font-size:13px;color:#5f636b;text-align:{$align};">{$q}</td></tr>
<tr><td style="background:#1f2024;color:#c9cbd1;padding:16px 28px;font-size:12px;text-align:{$align};">{$footer}<br><a href="{$site}" style="color:#FFD700;">{$site}</a></td></tr>
</table></td></tr></table></body></html>
HTML;
    }

    public static function button(string $label, string $url): string
    {
        $l = e($label);
        $u = e($url);

        return "<p style=\"margin:24px 0;\"><a href=\"{$u}\" style=\"display:inline-block;background:#E30613;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 26px;border-radius:999px;\">{$l}</a></p>";
    }
}
