<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Every state-changing admin action writes one row here. Never put secret values in $details. */
class Audit
{
    public static function log(?Request $r, string $action, string $entity = '', string|int|null $entityId = '', array $details = [], ?object $admin = null): void
    {
        $admin ??= $r?->attributes->get('admin');
        DB::table('audit_log')->insert([
            'admin_id' => $admin->id ?? null,
            'admin_name' => $admin->name ?? 'system',
            'action' => substr($action, 0, 60),
            'entity' => substr($entity, 0, 60),
            'entity_id' => substr((string) ($entityId ?? ''), 0, 60),
            'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'ip' => $r ? ClientIp::of($r) : '',
            'created_at' => now(),
        ]);
    }
}
