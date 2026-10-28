<?php

namespace App\Support;

/**
 * Mobile numbers: the customer picks the country code, then types the rest. Checked the same way in the browser
 * (assets/js/data.js TZ.phones, assets/js/staff.js) – keep the three lists in step.
 * Numbers are saved as "+<code> <number>", e.g. "+973 36001234", so WhatsApp and SMSA can use them as they are.
 */
class Phone
{
    /** country => [dial code, digits after the code, first digits allowed, example] – mobile numbers (WhatsApp). */
    public const RULES = [
        'BH' => ['973', [8], '/^[36]/', '3600 1234'],
        'SA' => ['966', [9], '/^5/', '50 123 4567'],
        'AE' => ['971', [9], '/^5/', '50 123 4567'],
        'QA' => ['974', [8], '/^[3567]/', '3312 3456'],
        'KW' => ['965', [8], '/^[4569]/', '5012 3456'],
        'OM' => ['968', [8], '/^[79]/', '9123 4567'],
        'EG' => ['20', [10], '/^1/', '10 1234 5678'],
        'JO' => ['962', [9], '/^7/', '79 123 4567'],
        'GB' => ['44', [10], '/^7/', '7400 123456'],
        'US' => ['1', [10], '/^[2-9]/', '201 555 0123'],
    ];

    /**
     * $raw is "+973 3600 1234" (code chosen + number typed), or a local number with $country as the code's country.
     * Returns [normalised, null] or [null, error message].
     */
    public static function check(string $raw, string $country = ''): array
    {
        $d = preg_replace('/\D/', '', $raw);
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        $rule = null;
        $local = '';
        $plus = str_starts_with(trim($raw), '+') || str_starts_with(preg_replace('/\s/', '', $raw), '00');
        // Longest matching code first (so 971 wins over 9…).
        $byLen = self::RULES;
        uasort($byLen, fn ($a, $b) => strlen($b[0]) <=> strlen($a[0]));
        if ($plus) {
            foreach ($byLen as $cc => $r) {
                if (str_starts_with($d, $r[0])) {
                    [$rule, $local] = [$r + ['cc' => $cc], substr($d, strlen($r[0]))];
                    break;
                }
            }
            if (! $rule) {
                return strlen($d) >= 8 && strlen($d) <= 15 ? ['+'.$d, null] : [null, 'Enter the phone number with its country code.'];
            }
        } else {
            $r = self::RULES[strtoupper($country)] ?? null;
            if (! $r) {
                return strlen($d) >= 8 && strlen($d) <= 15 ? ['+'.$d, null] : [null, 'Enter the phone number with its country code.'];
            }
            $rule = $r + ['cc' => strtoupper($country)];
            $local = str_starts_with($d, $r[0]) && strlen($d) > max($r[1]) ? substr($d, strlen($r[0])) : $d;
        }
        $local = ltrim($local, '0'); // a trunk 0 typed out of habit (05x… in Saudi / UAE)
        if (! in_array(strlen($local), $rule[1], true) || ! preg_match($rule[2], $local)) {
            return [null, 'This mobile number does not look right for the country code chosen (example: +'.$rule[0].' '.$rule[3].').'];
        }

        return ['+'.$rule[0].' '.$local, null];
    }
}
