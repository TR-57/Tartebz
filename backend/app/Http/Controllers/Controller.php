<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function ok(array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(['ok' => true] + $data, $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function admin(Request $r): object
    {
        return $r->attributes->get('admin');
    }

    /** A trimmed string input, control characters removed, capped at $max characters. */
    protected function str(Request $r, string $key, int $max = 255): string
    {
        $v = $r->input($key);
        if (! is_scalar($v)) {
            return '';
        }

        return mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $v)), 0, $max);
    }

    protected function bool(Request $r, string $key, bool $default = false): bool
    {
        $v = $r->input($key);

        return $v === null ? $default : filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }
}
