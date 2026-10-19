<?php

namespace Tests\Feature\Auth;

use App\Services\InternalIdentityService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EntraAuthenticationTest extends TestCase
{
    public function test_login_uses_tenant_scoped_authorization_code_with_pkce(): void
    {
        config([
            'services.entra.tenant_id' => '11111111-1111-1111-1111-111111111111',
            'services.entra.client_id' => '22222222-2222-2222-2222-222222222222',
            'services.entra.client_secret' => 'secret',
            'services.entra.redirect' => 'https://signature.example/auth/entra/callback',
        ]);
        $response = $this->get('/auth/entra');
        $response->assertRedirectContains('login.microsoftonline.com/11111111-1111-1111-1111-111111111111');
        $response->assertRedirectContains('response_type=code');
        $response->assertRedirectContains('code_challenge_method=S256');
        $this->assertNotEmpty(session('oidc_nonce'));
    }

    public function test_student_and_external_accounts_are_rejected(): void
    {
        config(['services.entra.allowed_domains' => ['acarts.be'], 'services.entra.forbidden_username_fragment' => '.ELEVE']);
        $service = app(InternalIdentityService::class);
        $this->assertSame('prof@acarts.be', $service->normalizeAndValidate('PROF@acarts.be'));
        foreach (['prenom.ELEVE@acarts.be', 'guest#EXT#@acarts.be', 'personne@example.net'] as $email) {
            try {
                $service->normalizeAndValidate($email);
                $this->fail("$email aurait dû être refusé");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }
}
