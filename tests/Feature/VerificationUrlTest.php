<?php

namespace Tests\Feature;

use App\Models\SignatureRequest;
use Tests\TestCase;

class VerificationUrlTest extends TestCase
{
    public function test_qr_code_url_uses_app_url_by_default(): void
    {
        config(['app.url' => 'https://sign.intra.acarts.be', 'app.verify_url' => null]);
        url()->forceRootUrl('https://sign.intra.acarts.be');
        $request = new SignatureRequest(['verification_code' => 'AbC123']);

        $this->assertSame('https://sign.intra.acarts.be/verifier/AbC123', $request->verificationUrl());
    }

    public function test_qr_code_url_uses_public_verify_url_when_defined(): void
    {
        config(['app.url' => 'https://sign.intra.acarts.be', 'app.verify_url' => 'https://sign.acarts.be/']);
        $request = new SignatureRequest(['verification_code' => 'AbC123']);

        $this->assertSame('https://sign.acarts.be/verifier/AbC123', $request->verificationUrl());
    }
}
