<?php

namespace App\Http\Middleware;

use App\Support\Roles;
use Closure;
use Illuminate\Http\Request;

/**
 * role:manager           – ladder check (manager or owner; never the order-team roles)
 * roles:order_manager,managers – any of the named roles ("managers" = owner or manager, "staff+" = ladder)
 */
class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $have = $request->attributes->get('admin')->role ?? '';
        $ok = count($roles) === 1 && ! str_contains($roles[0], '_') && $roles[0] !== 'managers' && ! str_ends_with($roles[0], '+')
            ? Roles::atLeast($have, $roles[0])
            : Roles::any($have, $roles);
        if (! $ok) {
            throw Roles::denied($have);
        }

        return $next($request);
    }
}
