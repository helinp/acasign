<?php

namespace Tests\Feature\Api;

use App\Jobs\DispatchDocumentNotification;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_api_creates_document_and_is_idempotent_by_external_reference(): void
    {
        Queue::fake([DispatchDocumentNotification::class]);
        Storage::fake('local');
        config(['services.api.jwt_secret' => 'a-secret-of-at-least-32-characters', 'services.api.jwt_issuer' => 'integration', 'services.entra.allowed_domains' => ['ecole.test']]);
        User::factory()->create(['email' => 'rh@ecole.test']);
        $token = JWT::encode(['iss' => 'integration', 'sub' => 'tests', 'exp' => time() + 60, 'scope' => ['documents:write']], config('services.api.jwt_secret'), 'HS256');
        $payload = fn () => [
            'file' => UploadedFile::fake()->createWithContent('contrat.pdf', $this->pdfBytes()),
            'title' => 'Contrat', 'external_reference' => 'RH-42', 'requester_email' => 'rh@ecole.test',
            'signers' => [['email' => 'agent@ecole.test']],
        ];
        $first = $this->withToken($token)->post('/api/v1/documents', $payload());
        $first->assertCreated()->assertJsonPath('external_reference', 'RH-42');
        $this->withToken($token)->post('/api/v1/documents', $payload())->assertOk()->assertJsonPath('id', $first->json('id'));
        $this->assertDatabaseCount('documents', 1);
        Queue::assertPushed(DispatchDocumentNotification::class, 1);
    }

    public function test_api_returns_401_without_bearer_token(): void
    {
        config(['services.api.jwt_secret' => 'a-secret-of-at-least-32-characters']);

        $this->postJson('/api/v1/documents')->assertUnauthorized();

        $this->assertDatabaseCount('documents', 0);
    }

    private function pdfBytes(): string
    {
        $pdf = new \TCPDF;
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Write(0, 'Document API');

        return $pdf->Output('', 'S');
    }
}
