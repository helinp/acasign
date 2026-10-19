<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->disabled_at) {
            auth()->logout();
            $request->session()->invalidate();
            abort(403, 'Ce compte a été désactivé.');
        }

        return $next($request);
    }
}
