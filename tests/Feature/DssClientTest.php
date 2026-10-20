<?php

namespace Tests\Feature;

use App\Services\DssClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DssClientTest extends TestCase
{
    public function test_data_to_sign_and_sign_document_use_identical_pades_parameters(): void
    {
        config(['services.dss.url' => 'http://dss.test', 'services.dss.signature_level' => 'PAdES_BASELINE_B']);
        Http::fake([
            'dss.test/services/rest/signature/one-document/getDataToSign' => Http::response(['bytes' => base64_encode('octets-a-signer')]),
            'dss.test/services/rest/signature/one-document/signDocument' => Http::response(['bytes' => base64_encode('%PDF-signe'), 'name' => 'document.pdf']),
        ]);
        $dss = app(DssClient::class);
        $parameters = $dss->parameters('Q0VSVA==', 'SHA-384', 'ECC', 1792400000000);

        $this->assertSame('octets-a-signer', $dss->dataToSign('%PDF', $parameters));
        $this->assertSame('%PDF-signe', $dss->signDocument('%PDF', $parameters, 'ECDSA_SHA384', 'signature'));

        $recorded = Http::recorded()->map(fn (array $pair) => $pair[0]);
        $this->assertCount(2, $recorded);
        $this->assertSame($recorded[0]['parameters'], $recorded[1]['parameters']);
        Http::assertSent(fn (Request $request) => $request['parameters']['signatureLevel'] === 'PAdES_BASELINE_B'
            && $request['parameters']['digestAlgorithm'] === 'SHA384'
            && $request['parameters']['encryptionAlgorithm'] === 'ECDSA'
            && $request['parameters']['signaturePackaging'] === 'ENVELOPED'
            && $request['parameters']['blevelParams']['signingDate'] === 1792400000000
            && $request['toSignDocument']['bytes'] === base64_encode('%PDF'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'signDocument')
            && $request['signatureValue'] === ['algorithm' => 'ECDSA_SHA384', 'value' => base64_encode('signature')]);
    }

    public function test_unsupported_hash_function_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(DssClient::class)->parameters('Q0VSVA==', 'SHA-1', 'RSA', 0);
    }

    public function test_dss_error_is_reported(): void
    {
        config(['services.dss.url' => 'http://dss.test']);
        Http::fake(['dss.test/*' => Http::response(['message' => 'Erreur'], 500)]);

        $this->expectException(RequestException::class);

        app(DssClient::class)->dataToSign('%PDF', app(DssClient::class)->parameters('Q0VSVA==', 'SHA-256', 'RSA', 0));
    }
}
