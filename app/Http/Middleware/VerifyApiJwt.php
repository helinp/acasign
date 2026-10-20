<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie les appels de l'API d'intégration (JWT HS256, émetteur et portée « documents:write »).
 */
class VerifyApiJwt
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        abort_unless($token && config('services.api.jwt_secret'), 401, 'Jeton API manquant.');

        try {
            $claims = JWT::decode($token, new Key(config('services.api.jwt_secret'), 'HS256'));
        } catch (\Throwable) {
            abort(401, 'Jeton API invalide.');
        }

        abort_unless(($claims->iss ?? null) === config('services.api.jwt_issuer'), 401, 'Émetteur invalide.');
        abort_unless(in_array('documents:write', (array) ($claims->scope ?? []), true), 403, 'Portée insuffisante.');
        $request->attributes->set('api_client', $claims->sub ?? 'api');

        return $next($request);
    }
}
