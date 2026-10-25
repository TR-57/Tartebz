<?php

namespace App\Support;

/**
 * staff (1) < manager (2) < owner (3). The order-team roles sit outside the ladder (rank 0):
 * a ladder check never lets them through; they are only allowed where named explicitly.
 */
class Roles
{
    public const RANK = ['staff' => 1, 'manager' => 2, 'owner' => 3, 'order_manager' => 0, 'order_assistant' => 0];

    public const ALL = ['owner', 'manager', 'staff', 'order_manager', 'order_assistant'];

    public const LABELS = [
        'owner' => 'Owner',
        'manager' => 'Manager',
        'staff' => 'Staff member',
        'order_manager' => 'Order manager',
        'order_assistant' => 'Order assistant',
    ];

    public static function atLeast(string $have, string $need): bool
    {
        $n = self::RANK[$need] ?? 99;

        return $n > 0 && (self::RANK[$have] ?? 0) >= $n;
    }

    /** $roles may name roles exactly, or the pseudo-role "managers" (owner or manager), or a ladder role with "+" (e.g. "staff+"). */
    public static function any(string $have, array $roles): bool
    {
        foreach ($roles as $r) {
            if ($r === 'managers' && in_array($have, ['owner', 'manager'], true)) {
                return true;
            }
            if (str_ends_with($r, '+') && self::atLeast($have, substr($r, 0, -1))) {
                return true;
            }
            if ($r === $have) {
                return true;
            }
        }

        return false;
    }

    public static function denied(string $have): ApiError
    {
        return ApiError::forbidden("Your role ({$have}) cannot do this. Ask the store owner.");
    }
}
