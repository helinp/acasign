<?php

namespace Tests\Feature\Auth;

use App\Services\InternalIdentityService;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EntraAuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_login_uses_tenant_scoped_authorization_code_with_pkce(): void
    {
        config([
            'services.entra.tenant_id' => '11111111-1111-1111-1111-111111111111',
            'services.entra.client_id' => '22222222-2222-2222-2222-222222222222',
            'services.entra.client_secret' => 'secret',
            'services.entra.redirect' => 'https://signature.example/auth/entra/callback',
        ]);
        $response = $this->get('/auth/entra');
        $response->assertRedirectContains('https://login.microsoftonline.com/11111111-1111-1111-1111-111111111111/oauth2/v2.0/authorize?');
        $response->assertRedirectContains('response_type=code');
        $response->assertRedirectContains('code_challenge_method=S256');
        $this->assertNotEmpty(session('oidc_nonce'));
    }

    public function test_callback_accepts_microsoft_keys_published_without_alg(): void
    {
        config(['services.entra.tenant_id' => 'tenant-id', 'services.entra.client_id' => 'client-id', 'services.entra.client_secret' => 'secret',
            'services.entra.redirect' => 'https://signature.example/auth/entra/callback', 'services.entra.allowed_domains' => ['ecole.test']]);
        $privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $rsa = openssl_pkey_get_details($privateKey)['rsa'];
        $base64Url = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $issuer = 'https://login.microsoftonline.com/tenant-id/v2.0';
        $idToken = JWT::encode([
            'iss' => $issuer, 'aud' => 'client-id', 'tid' => 'tenant-id', 'oid' => 'oid-1', 'nonce' => 'nonce',
            'preferred_username' => 'prof@ecole.test', 'name' => 'Prof Test', 'iat' => time(), 'exp' => time() + 300,
        ], $privateKey, 'RS256', 'key-1');
        Http::fake([
            '*/oauth2/v2.0/token' => Http::response(['id_token' => $idToken]),
            '*/.well-known/openid-configuration' => Http::response(['issuer' => $issuer, 'jwks_uri' => 'https://login.microsoftonline.com/tenant-id/discovery/v2.0/keys']),
            '*/discovery/v2.0/keys' => Http::response(['keys' => [['kty' => 'RSA', 'use' => 'sig', 'kid' => 'key-1', 'n' => $base64Url($rsa['n']), 'e' => $base64Url($rsa['e'])]]]),
        ]);

        $this->withSession(['oidc_state' => 'etat', 'oidc_verifier' => 'v', 'oidc_nonce' => 'nonce'])
            ->get('/auth/entra/callback?code=abc&state=etat')
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_failed_token_exchange_returns_to_login_with_a_message(): void
    {
        config(['services.entra.tenant_id' => 'tenant', 'services.entra.client_id' => 'client', 'services.entra.client_secret' => 'wrong', 'services.entra.redirect' => 'https://signature.example/auth/entra/callback']);
        Http::fake(['login.microsoftonline.com/*' => Http::response(['error' => 'invalid_client', 'error_description' => 'AADSTS7000215: Invalid client secret provided.'], 401)]);

        $this->withSession(['oidc_state' => 'etat', 'oidc_verifier' => 'v', 'oidc_nonce' => 'n'])
            ->get('/auth/entra/callback?code=abc&state=etat')
            ->assertRedirect(route('login'))->assertSessionHasErrors('entra');
        $this->assertGuest();
    }

    public function test_error_returned_by_microsoft_is_shown_on_login(): void
    {
        $this->get('/auth/entra/callback?error=access_denied&error_description=AADSTS50105')
            ->assertRedirect(route('login'))->assertSessionHasErrors('entra');
    }

    public function test_student_and_external_accounts_are_rejected(): void
    {
        config(['services.entra.allowed_domains' => ['ecole.test'], 'services.entra.forbidden_username_fragment' => '.ELEVE']);
        $service = app(InternalIdentityService::class);
        $this->assertSame('prof@ecole.test', $service->normalizeAndValidate('PROF@ecole.test'));
        foreach (['prenom.ELEVE@ecole.test', 'guest#EXT#@ecole.test', 'personne@example.net'] as $email) {
            try {
                $service->normalizeAndValidate($email);
                $this->fail("$email aurait dû être refusé");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }
}
