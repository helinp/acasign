<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyAcanetJwt
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        abort_unless($token && config('services.acanet.jwt_secret'), 401, 'Jeton API manquant.');

        try {
            $claims = JWT::decode($token, new Key(config('services.acanet.jwt_secret'), 'HS256'));
        } catch (\Throwable) {
            abort(401, 'Jeton API invalide.');
        }

        abort_unless(($claims->iss ?? null) === config('services.acanet.jwt_issuer'), 401, 'Émetteur invalide.');
        abort_unless(in_array('documents:write', (array) ($claims->scope ?? []), true), 403, 'Portée insuffisante.');
        $request->attributes->set('api_client', $claims->sub ?? 'acanet');

        return $next($request);
    }
}
