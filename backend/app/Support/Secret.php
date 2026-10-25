<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

/**
 * Seals secrets (gateway keys, SMTP passwords, tokens) with the app key before they are saved.
 * A secret that can no longer be opened (the app key changed) reads as empty, so it must be entered again.
 */
class Secret
{
    public static function seal(string $plain): string
    {
        return $plain === '' ? '' : Crypt::encryptString($plain);
    }

    public static function open(?string $sealed): string
    {
        if ($sealed === null || $sealed === '') {
            return '';
        }
        try {
            return Crypt::decryptString($sealed);
        } catch (\Throwable) {
            return '';
        }
    }

    /** What the admin may see about a secret: whether it is set and its last four characters. */
    public static function describe(?string $sealed, bool $showLast4 = true): array
    {
        $plain = self::open($sealed);

        return ['set' => $plain !== '', 'last4' => ($showLast4 && strlen($plain) >= 8) ? substr($plain, -4) : ''];
    }
}
