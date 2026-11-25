<?php

namespace Tests\Feature;

use App\Jobs\DispatchDocumentNotification;
use App\Models\Document;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Services\EncryptedSignatureStorage;
use App\Services\PdfService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SimpleSignatureTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_signer_can_leave_out_the_qr_code(): void
    {
        Queue::fake([DispatchDocumentNotification::class]);
        Storage::fake('local');
        $this->configureSealCertificate();
        [$signer, $signatureRequest] = $this->pendingSignature();
        $signer->update(['signature_path' => app(EncryptedSignatureStorage::class)->store($signer, UploadedFile::fake()->image('signature.png', 40, 20))]);

        $this->actingAs($signer)->post(route('signatures.sign', $signatureRequest), ['consent' => 1, 'show_name' => 1, 'show_date' => 1, 'show_qr' => 0])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('documents.show', $signatureRequest->document_id));

        $this->assertFalse($signatureRequest->refresh()->showsQrCode());
        $this->assertSame('signed', $signatureRequest->document->status);
    }

    public function test_duplicate_submission_rejected_under_lock_keeps_the_concurrently_signed_pdf(): void
    {
        Storage::fake('local');
        [$signer, $signatureRequest] = $this->pendingSignature();
        $signer->update(['signature_path' => 'signatures/x.enc']);
        $path = $signatureRequest->document->file_path;
        $outerLevel = DB::transactionLevel();

        // Double envoi : la première requête signe pendant que celle-ci attend le verrou.
        SignatureRequest::retrieved(function (SignatureRequest $locked) use ($path, $outerLevel): void {
            if (DB::transactionLevel() > $outerLevel && $locked->status === 'pending') {
                Storage::disk('local')->put($path, 'PDF signé par la première requête');
                $locked->status = 'signed';
            }
        });

        $this->actingAs($signer)->post(route('signatures.sign', $signatureRequest), ['consent' => 1, 'show_name' => 1])
            ->assertStatus(409)
            ->assertSee('Cette demande n’est plus signable.');

        $this->assertSame('PDF signé par la première requête', Storage::disk('local')->get($path));
    }

    public function test_qr_code_is_kept_when_the_choice_is_not_sent(): void
    {
        $this->assertTrue((new SignatureRequest)->showsQrCode());
        $this->assertTrue((new SignatureRequest(['display_options' => ['show_name' => true]]))->showsQrCode());
        $this->assertFalse((new SignatureRequest(['display_options' => ['show_qr' => false]]))->showsQrCode());
    }

    public function test_stamp_without_qr_code_embeds_only_the_signature_image(): void
    {
        Storage::fake('local');
        [, $signatureRequest] = $this->pendingSignature();
        $document = $signatureRequest->document;
        $imagesAfterStamp = function (bool $showQr) use ($document, $signatureRequest): int {
            Storage::disk('local')->put($document->file_path, $this->pdfBytes());
            $signatureRequest->display_options = ['show_qr' => $showQr];
            app(PdfService::class)->stamp($document, $signatureRequest, $this->pngBytes());

            return substr_count(Storage::disk('local')->get($document->file_path), '/Subtype /Image');
        };

        $this->assertSame(1, $imagesAfterStamp(false));
        $this->assertGreaterThan(1, $imagesAfterStamp(true));
    }

    public function test_seal_leaves_the_pdf_open_to_further_signatures(): void
    {
        Storage::fake('local');
        $this->configureSealCertificate();
        [, $signatureRequest] = $this->pendingSignature();

        app(PdfService::class)->seal($signatureRequest->document);

        $sealed = Storage::disk('local')->get($signatureRequest->document->file_path);
        $this->assertStringContainsString('/ByteRange', $sealed);
        $this->assertStringContainsString('/SigFlags 3', $sealed);
        $this->assertStringNotContainsString('/DocMDP', $sealed);
        $this->assertStringNotContainsString('/Perms', $sealed);
    }

    /**
     * Certificat autosigné de test pour le cachet final, sur le disque simulé.
     */
    private function configureSealCertificate(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'AcaSign test'], $key), null, $key, 1);
        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $keyPem);
        Storage::disk('local')->put('seal/certificate.pem', $certificatePem);
        Storage::disk('local')->put('seal/private-key.pem', $keyPem);

        config([
            'services.signature.certificate' => Storage::disk('local')->path('seal/certificate.pem'),
            'services.signature.private_key' => Storage::disk('local')->path('seal/private-key.pem'),
            'services.signature.private_key_password' => null,
        ]);
    }

    /**
     * Document d'une page avec un seul signataire en attente.
     *
     * @return array{0: User, 1: SignatureRequest}
     */
    private function pendingSignature(): array
    {
        $requester = User::factory()->create(['can_send' => true]);
        $signer = User::factory()->create(['email' => 'signataire@ecole.test']);
        $id = (string) Str::uuid();
        $document = Document::create([
            'id' => $id, 'verification_code' => Str::random(48), 'requester_id' => $requester->id,
            'title' => 'Convention', 'status' => 'pending', 'original_name' => 'convention.pdf',
            'file_path' => "documents/{$id}/current.pdf", 'file_size' => 100, 'page_count' => 1,
            'sha256' => str_repeat('a', 64), 'expires_at' => now()->addDay(),
        ]);
        Storage::disk('local')->put($document->file_path, $this->pdfBytes());
        $signatureRequest = SignatureRequest::create([
            'document_id' => $document->id, 'signer_id' => $signer->id, 'signer_email' => $signer->email, 'signer_name' => $signer->name,
            'page' => 1, 'position_x' => 0.6, 'position_y' => 0.72, 'width' => 0.28, 'height' => 0.12,
        ]);

        return [$signer, $signatureRequest];
    }

    private function pdfBytes(): string
    {
        $pdf = new \TCPDF;
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        return $pdf->Output('', 'S');
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(20, 10);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
