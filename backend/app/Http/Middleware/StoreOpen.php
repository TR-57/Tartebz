<?php

namespace App\Http\Middleware;

use App\Support\ApiError;
use App\Support\Maintenance;
use Closure;
use Illuminate\Http\Request;

/** Customer actions (ordering, reserving stock, paying again, back-in-stock requests) pause in maintenance mode. The team is let through. */
class StoreOpen
{
    public function handle(Request $request, Closure $next)
    {
        if (Maintenance::blocks($request)) {
            $m = Maintenance::info();
            $msg = $request->input('lang') === 'en' || ! $m['messageAr'] ? ($m['message'] ?: 'The shop is closed for a short update. Please try again soon, or order on WhatsApp.') : $m['messageAr'];
            throw new ApiError('maintenance', $msg, 503);
        }

        return $next($request);
    }
}
