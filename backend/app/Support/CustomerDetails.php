<?php

namespace App\Support;

/**
 * The customer's name, contact and delivery address – the same rules at checkout and when the team edits an order.
 * Lengths follow what couriers accept (SMSA: address lines up to 100 characters, city and district up to 50).
 */
class CustomerDetails
{
    public const MAX = ['first_name' => 80, 'last_name' => 80, 'email' => 190, 'phone' => 30, 'address1' => 100, 'address2' => 100, 'city' => 50, 'state' => 50];

    /**
     * $in: first_name, last_name, email, phone, address1, address2, city, state. Returns [clean values, field errors].
     * $emailRequired: card payments need an email for the receipt.
     */
    public static function check(array $in, string $country, bool $emailRequired): array
    {
        $c = [];
        foreach (self::MAX as $k => $max) {
            $c[$k] = trim(preg_replace('/\s+/u', ' ', (string) ($in[$k] ?? '')));
        }
        $c['email'] = mb_strtolower($c['email']);
        $f = [];
        foreach (['first_name' => 'Enter your first name.', 'last_name' => 'Enter your last name.', 'address1' => 'Enter your street / area.', 'city' => 'Enter your city.'] as $k => $msg) {
            if (mb_strlen($c[$k]) < 2) {
                $f[$k] = $msg;
            }
        }
        $too = [
            'address1' => 'Keep the address under 100 characters – put the rest (building, flat, landmark) in the second line.',
            'address2' => 'Keep this line under 100 characters.',
            'city' => 'Keep the city under 50 characters.',
            'state' => 'Keep the area under 50 characters.',
            'first_name' => 'Keep the name under 80 characters.',
            'last_name' => 'Keep the name under 80 characters.',
            'email' => 'Enter a valid email.',
        ];
        foreach ($too as $k => $msg) {
            if (! isset($f[$k]) && mb_strlen($c[$k]) > self::MAX[$k]) {
                $f[$k] = $msg;
            }
        }
        if (! isset($f['email']) && ($c['email'] !== '' || $emailRequired) && ! filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
            $f['email'] = 'Enter a valid email.';
        }
        [$phone, $phoneErr] = Phone::check($c['phone'], $country);
        if ($phoneErr) {
            $f['phone'] = $phoneErr;
        } else {
            $c['phone'] = $phone;
        }

        return [$c, $f];
    }
}
