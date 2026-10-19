<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class EntraOidcService
{
    private function authority(): string
    {
        return 'https://login.microsoftonline.com/'.config('services.entra.tenant_id').'/v2.0';
    }

    public function authorizationUrl(): string
    {
        foreach (['tenant_id', 'client_id', 'client_secret', 'redirect'] as $key) {
            throw_unless(config("services.entra.$key"), new \RuntimeException("Configuration Entra manquante : $key"));
        }
        $state = Str::random(64);
        $nonce = Str::random(64);
        $verifier = Str::random(96);
        session(['oidc_state' => $state, 'oidc_nonce' => $nonce, 'oidc_verifier' => $verifier]);

        return $this->authority().'/oauth2/v2.0/authorize?'.http_build_query([
            'client_id' => config('services.entra.client_id'),
            'response_type' => 'code',
            'redirect_uri' => config('services.entra.redirect'),
            'response_mode' => 'query',
            'scope' => 'openid profile email',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function exchangeAndValidate(string $code, string $state): array
    {
        throw_unless(hash_equals((string) session()->pull('oidc_state'), $state), new \RuntimeException('État OIDC invalide.'));
        $response = Http::asForm()->connectTimeout(5)->timeout(15)->post($this->authority().'/oauth2/v2.0/token', [
            'client_id' => config('services.entra.client_id'),
            'client_secret' => config('services.entra.client_secret'),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.entra.redirect'),
            'code_verifier' => session()->pull('oidc_verifier'),
            'scope' => 'openid profile email',
        ])->throw()->json();

        $discovery = Http::connectTimeout(3)->timeout(10)->get($this->authority().'/.well-known/openid-configuration')->throw()->json();
        $jwks = cache()->remember('entra-jwks', 3600, fn () => Http::connectTimeout(3)->timeout(10)->get($discovery['jwks_uri'])->throw()->json());
        $claims = (array) JWT::decode($response['id_token'], JWK::parseKeySet($jwks));

        throw_unless(($claims['aud'] ?? null) === config('services.entra.client_id'), new \RuntimeException('Audience Entra invalide.'));
        throw_unless(($claims['tid'] ?? null) === config('services.entra.tenant_id'), new \RuntimeException('Tenant Entra invalide.'));
        throw_unless(($claims['iss'] ?? null) === ($discovery['issuer'] ?? null), new \RuntimeException('Émetteur Entra invalide.'));
        throw_unless(isset($claims['oid']), new \RuntimeException('Identifiant Entra absent.'));
        throw_unless(hash_equals((string) session()->pull('oidc_nonce'), (string) ($claims['nonce'] ?? '')), new \RuntimeException('Nonce OIDC invalide.'));

        return $claims;
    }
}
