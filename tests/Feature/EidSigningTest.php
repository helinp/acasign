<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentField;
use App\Models\SignatureRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class EidSigningTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const DATA_TO_SIGN = 'octets-a-signer-par-la-carte';

    private \OpenSSLAsymmetricKey $key;

    private string $certificate;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.dss.url' => 'http://dss.test']);
        Http::fake([
            'dss.test/services/rest/signature/one-document/getDataToSign' => Http::response(['bytes' => base64_encode(self::DATA_TO_SIGN)]),
            'dss.test/services/rest/signature/one-document/signDocument' => Http::response(['bytes' => base64_encode('%PDF-1.7 signé eID'), 'name' => 'document.pdf']),
        ]);
        [$this->key, $this->certificate] = $this->signingCertificate();
    }

    public function test_signer_signs_with_eid_card_and_document_is_completed(): void
    {
        [$signer, $document, $signatureRequest] = $this->eidDocument();

        $prepared = $this->actingAs($signer)->postJson(route('signatures.eid.prepare', $signatureRequest), [
            'certificate' => $this->certificate, 'consent' => '1',
            'supportedSignatureAlgorithms' => [['cryptoAlgorithm' => 'ECC', 'hashFunction' => 'SHA-384', 'paddingScheme' => 'NONE']],
            'show_name' => '1', 'show_date' => '1', 'show_job_title' => '0',
        ])->assertOk()->assertJsonPath('hashFunction', 'SHA-384');
        $this->assertSame(base64_encode(hash('sha384', self::DATA_TO_SIGN, true)), $prepared->json('hash'));

        $this->actingAs($signer)->postJson(route('signatures.eid.finalize', $signatureRequest), [
            'signature' => base64_encode($this->cardSignature(self::DATA_TO_SIGN)),
            'signatureAlgorithm' => ['cryptoAlgorithm' => 'ECC', 'hashFunction' => 'SHA-384', 'paddingScheme' => 'NONE'],
        ])->assertOk()->assertJsonPath('redirect', route('documents.show', $document));

        $this->assertSame('%PDF-1.7 signé eID', Storage::disk('local')->get($document->file_path));
        $this->assertSame('signed', $document->fresh()->status);
        $signatureRequest->refresh();
        $this->assertSame('signed', $signatureRequest->status);
        $this->assertSame('Marie Dupont (Signature)', $signatureRequest->certificate_info['subject']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'signDocument')
            && $request['signatureValue']['algorithm'] === 'ECDSA_SHA384'
            && $request['parameters']['imageParameters']['fieldParameters']['page'] === 1);
    }

    public function test_signature_not_matching_the_certificate_is_refused(): void
    {
        [$signer, $document, $signatureRequest] = $this->eidDocument();
        $this->prepare($signer, $signatureRequest);

        $this->actingAs($signer)->postJson(route('signatures.eid.finalize', $signatureRequest), [
            'signature' => base64_encode($this->cardSignature('autre contenu')),
            'signatureAlgorithm' => ['cryptoAlgorithm' => 'ECC', 'hashFunction' => 'SHA-384', 'paddingScheme' => 'NONE'],
        ])->assertJsonValidationErrors('eid');

        $this->assertSame('pending', $signatureRequest->fresh()->status);
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), 'signDocument'));
    }

    public function test_document_changed_since_preparation_must_be_signed_again(): void
    {
        [$signer, $document, $signatureRequest] = $this->eidDocument();
        $this->prepare($signer, $signatureRequest);
        Storage::disk('local')->put($document->file_path, '%PDF-1.7 signé par un autre');

        $this->actingAs($signer)->postJson(route('signatures.eid.finalize', $signatureRequest), [
            'signature' => base64_encode($this->cardSignature(self::DATA_TO_SIGN)),
            'signatureAlgorithm' => ['cryptoAlgorithm' => 'ECC', 'hashFunction' => 'SHA-384', 'paddingScheme' => 'NONE'],
        ])->assertJsonValidationErrors('eid');
    }

    public function test_authentication_certificate_is_refused(): void
    {
        [$signer, , $signatureRequest] = $this->eidDocument();
        [, $authentication] = $this->signingCertificate('digitalSignature');

        $this->actingAs($signer)->postJson(route('signatures.eid.prepare', $signatureRequest), [
            'certificate' => $authentication, 'consent' => '1',
            'supportedSignatureAlgorithms' => [['cryptoAlgorithm' => 'ECC', 'hashFunction' => 'SHA-384', 'paddingScheme' => 'NONE']],
        ])->assertJsonValidationErrors('eid');
    }

    public function test_other_user_cannot_prepare_an_eid_signature(): void
    {
        [, , $signatureRequest] = $this->eidDocument();

        $this->actingAs(User::factory()->create())->postJson(route('signatures.eid.prepare', $signatureRequest), [
            'certificate' => $this->certificate, 'consent' => '1',
            'supportedSignatureAlgorithms' => [['cryptoAlgorithm' => 'ECC', 'hashFunction' => 'SHA-384', 'paddingScheme' => 'NONE']],
        ])->assertForbidden();
    }

    public function test_eid_signature_requires_reading_confirmation(): void
    {
        [$signer, , $signatureRequest] = $this->eidDocument();

        $this->actingAs($signer)->postJson(route('signatures.eid.prepare', $signatureRequest), [
            'certificate' => $this->certificate,
            'supportedSignatureAlgorithms' => [['cryptoAlgorithm' => 'ECC', 'hashFunction' => 'SHA-384', 'paddingScheme' => 'NONE']],
        ])->assertJsonValidationErrors('consent');
        Http::assertNothingSent();
    }

    public function test_card_test_reads_the_signing_certificate(): void
    {
        $user = User::factory()->create();
        [, $authentication] = $this->signingCertificate('digitalSignature');

        $this->actingAs($user)->postJson(route('profile.eid.check'), ['certificate' => $this->certificate])
            ->assertOk()->assertJsonPath('subject', 'Marie Dupont (Signature)');
        $this->actingAs($user)->postJson(route('profile.eid.check'), ['certificate' => $authentication])
            ->assertJsonValidationErrors('eid');
    }

    public function test_sign_page_shows_explicit_eid_sign_button(): void
    {
        [$signer, , $signatureRequest] = $this->eidDocument();

        $this->actingAs($signer)->get(route('signatures.show', $signatureRequest))
            ->assertOk()
            ->assertSee('data-open-sign="eid"', false)
            ->assertDontSee('data-open-sign="internal"', false);
    }

    public function test_internal_document_offers_both_choices_and_locks_to_eid_after_an_eid_signature(): void
    {
        [$signer, $document, $signatureRequest] = $this->eidDocument();
        $document->update(['signature_method' => 'internal']);
        $colleague = User::factory()->create(['email' => 'collegue@ecole.test', 'signature_path' => 'signatures/c.enc']);
        $other = SignatureRequest::create(['document_id' => $document->id, 'signer_id' => $colleague->id, 'signer_email' => $colleague->email, 'signer_name' => $colleague->name]);

        $this->actingAs($signer)->get(route('signatures.show', $signatureRequest))
            ->assertSee('data-open-sign="eid"', false)
            ->assertSee('data-open-sign="internal"', false);

        $this->prepare($signer, $signatureRequest);
        $this->actingAs($signer)->postJson(route('signatures.eid.finalize', $signatureRequest), [
            'signature' => base64_encode($this->cardSignature(self::DATA_TO_SIGN)),
            'signatureAlgorithm' => ['cryptoAlgorithm' => 'ECC', 'hashFunction' => 'SHA-384', 'paddingScheme' => 'NONE'],
        ])->assertOk();

        $this->assertTrue($document->fresh()->usesEid());
        $this->assertSame('pending', $document->fresh()->status);
        $this->actingAs($colleague)->post(route('signatures.sign', $other), ['show_name' => 1])->assertStatus(409);
        $this->actingAs($colleague)->get(route('signatures.show', $other))->assertDontSee('data-open-sign="internal"', false);
    }

    public function test_eid_is_unavailable_while_another_signer_has_fields_to_complete(): void
    {
        [$signer, $document, $signatureRequest] = $this->eidDocument();
        $document->update(['signature_method' => 'internal']);
        $other = SignatureRequest::create(['document_id' => $document->id, 'signer_email' => 'collegue@ecole.test', 'signer_name' => 'Collègue']);
        DocumentField::create(['document_id' => $document->id, 'signature_request_id' => $other->id, 'type' => 'text', 'page' => 1, 'position_x' => 0.1, 'position_y' => 0.1, 'width' => 0.2, 'height' => 0.03]);

        $this->actingAs($signer)->postJson(route('signatures.eid.prepare', $signatureRequest), [
            'certificate' => $this->certificate, 'consent' => '1',
            'supportedSignatureAlgorithms' => [['cryptoAlgorithm' => 'ECC', 'hashFunction' => 'SHA-384', 'paddingScheme' => 'NONE']],
        ])->assertStatus(409);
        $this->actingAs($signer)->get(route('signatures.show', $signatureRequest))->assertDontSee('data-open-sign="eid"', false);
    }

    private function prepare(User $signer, SignatureRequest $signatureRequest): void
    {
        $this->actingAs($signer)->postJson(route('signatures.eid.prepare', $signatureRequest), [
            'certificate' => $this->certificate, 'consent' => '1',
            'supportedSignatureAlgorithms' => [['cryptoAlgorithm' => 'ECC', 'hashFunction' => 'SHA-384', 'paddingScheme' => 'NONE']],
        ])->assertOk();
    }

    /**
     * @return array{0: User, 1: Document, 2: SignatureRequest}
     */
    private function eidDocument(): array
    {
        $signer = User::factory()->create(['email' => 'marie.dupont@ecole.test', 'first_name' => 'Marie', 'last_name' => 'Dupont']);
        $document = Document::create([
            'id' => (string) Str::uuid(), 'verification_code' => Str::random(48), 'requester_id' => $signer->id,
            'title' => 'CAD 2026', 'status' => 'pending', 'original_name' => 'cad.pdf', 'signature_method' => 'eid',
            'file_path' => 'documents/cad/current.pdf', 'file_size' => 100, 'page_count' => 1, 'page_sizes' => [[595.28, 841.89]],
            'sha256' => str_repeat('a', 64), 'expires_at' => now()->addDay(),
        ]);
        Storage::disk('local')->put($document->file_path, '%PDF-1.7 original');
        $signatureRequest = SignatureRequest::create(['document_id' => $document->id, 'signer_id' => $signer->id, 'signer_email' => $signer->email, 'signer_name' => $signer->name]);

        return [$signer, $document, $signatureRequest];
    }

    /**
     * Signature « carte » : ECDSA P-384 au format brut r || s, comme le renvoie Web eID.
     */
    private function cardSignature(string $data): string
    {
        openssl_sign($data, $der, $this->key, OPENSSL_ALGO_SHA384);
        $offset = 2;
        $raw = '';
        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $raw .= str_pad(ltrim(substr($der, $offset + 2, $length), "\x00"), 48, "\x00", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $raw;
    }

    /**
     * Certificat auto-signé (P-384) avec l'usage de clé demandé ; « nonRepudiation » = certificat de signature.
     *
     * @return array{0: \OpenSSLAsymmetricKey, 1: string}
     */
    private function signingCertificate(string $keyUsage = 'nonRepudiation'): array
    {
        $config = tempnam(sys_get_temp_dir(), 'openssl-');
        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nkeyUsage=critical,{$keyUsage}\n");
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']);
        $csr = openssl_csr_new(['commonName' => 'Marie Dupont (Signature)', 'countryName' => 'BE'], $key, ['config' => $config, 'digest_alg' => 'sha384']);
        $x509 = openssl_csr_sign($csr, null, $key, 30, ['config' => $config, 'x509_extensions' => 'v3', 'digest_alg' => 'sha384']);
        openssl_x509_export($x509, $pem);
        @unlink($config);

        return [$key, preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem)];
    }
}
