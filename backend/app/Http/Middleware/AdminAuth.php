<?php

namespace App\Http\Middleware;

use App\Support\ApiError;
use App\Support\AuthSessions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Requires a signed-in, active admin. Puts the admin row on the request as attribute "admin". */
class AdminAuth
{
    public function handle(Request $request, Closure $next)
    {
        $admin = self::resolve($request);
        if (! $admin) {
            throw new ApiError('unauthenticated', 'Please sign in to the admin.', 401);
        }
        $request->attributes->set('admin', $admin);

        return $next($request);
    }

    public static function resolve(Request $request): ?object
    {
        $s = AuthSessions::current(AuthSessions::ADMIN, $request);
        if (! $s) {
            return null;
        }
        $admin = DB::table('admins')->where('id', $s->subject_id)->where('active', 1)->first();
        if ($admin) {
            unset($admin->password_hash);
        }

        return $admin;
    }
}
