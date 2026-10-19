<?php

namespace Tests\Feature;

use App\Jobs\DispatchDocumentNotification;
use App\Models\Document;
use App\Models\SignatureRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class DocumentWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_sender_creates_a_flattened_internal_signature_request(): void
    {
        Queue::fake([DispatchDocumentNotification::class]);
        Storage::fake('local');
        config(['services.entra.allowed_domains' => ['acarts.be']]);
        $sender = User::factory()->create(['email' => 'rh@acarts.be', 'can_send' => true]);

        $response = $this->actingAs($sender)->post('/documents', [
            'title' => 'Avenant 2026',
            'document' => UploadedFile::fake()->createWithContent('avenant.pdf', $this->pdfBytes()),
            'document_type' => 'Doc12',
            'signers' => ['collaborateur@acarts.be'],
            'expires_at' => now()->addDays(15)->format('Y-m-d'),
        ]);

        $document = Document::firstOrFail();
        $response->assertRedirect(route('documents.edit', $document));
        $this->assertSame('pending', $document->status);
        $this->assertSame(1, $document->page_count);
        $this->assertTrue(Storage::disk('local')->exists($document->file_path));
        $this->assertDatabaseHas('signature_requests', ['document_id' => $document->id, 'signer_email' => 'collaborateur@acarts.be']);
        Queue::assertPushed(DispatchDocumentNotification::class);
    }

    public function test_pdf_with_compressed_cross_reference_stream_is_accepted(): void
    {
        if ((new ExecutableFinder)->find('qpdf') === null) {
            $this->markTestSkipped('qpdf non installé.');
        }
        Queue::fake([DispatchDocumentNotification::class]);
        Storage::fake('local');
        config(['services.entra.allowed_domains' => ['acarts.be']]);
        $sender = User::factory()->create(['email' => 'rh@acarts.be', 'can_send' => true]);

        $response = $this->actingAs($sender)->post('/documents', [
            'title' => 'PDF 1.5',
            'document' => UploadedFile::fake()->createWithContent('compresse.pdf', $this->compressedPdfBytes()),
            'document_type' => 'Doc12',
            'signers' => ['collaborateur@acarts.be'],
            'expires_at' => now()->addDays(15)->format('Y-m-d'),
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, Document::firstOrFail()->page_count);
    }

    public function test_unreadable_pdf_returns_validation_error_instead_of_server_error(): void
    {
        Process::fake();
        Storage::fake('local');
        config(['services.entra.allowed_domains' => ['acarts.be']]);
        $sender = User::factory()->create(['email' => 'rh@acarts.be', 'can_send' => true]);

        $response = $this->actingAs($sender)->from(route('documents.create'))->post('/documents', [
            'title' => 'PDF illisible',
            'document' => UploadedFile::fake()->createWithContent('illisible.pdf', "%PDF-1.4\n%%EOF corrompu"),
            'document_type' => 'Doc12',
            'signers' => ['collaborateur@acarts.be'],
            'expires_at' => now()->addDays(15)->format('Y-m-d'),
        ]);

        $response->assertRedirect(route('documents.create'));
        $response->assertSessionHasErrors('document');
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_user_without_sender_permission_is_forbidden(): void
    {
        $user = User::factory()->create(['can_send' => false]);

        $this->actingAs($user)->post('/documents')->assertForbidden();

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_signer_can_open_signature_page(): void
    {
        $requester = User::factory()->create();
        $signer = User::factory()->create(['email' => 'signataire@acarts.be']);
        $document = Document::create([
            'id' => (string) Str::uuid(), 'verification_code' => Str::random(48), 'requester_id' => $requester->id,
            'title' => 'Avenant à signer', 'status' => 'pending', 'original_name' => 'avenant.pdf',
            'file_path' => 'documents/avenant/current.pdf', 'file_size' => 100, 'page_count' => 1,
            'sha256' => str_repeat('a', 64), 'expires_at' => now()->addDay(),
        ]);
        $signatureRequest = SignatureRequest::create([
            'document_id' => $document->id, 'signer_id' => $signer->id,
            'signer_email' => $signer->email, 'signer_name' => $signer->name,
        ]);

        $this->actingAs($signer)->get(route('signatures.show', $signatureRequest))
            ->assertOk()
            ->assertSee('Avenant à signer')
            ->assertSee('data-pdf-stage', false);
    }

    public function test_non_participant_cannot_read_document_metadata_or_file(): void
    {
        $requester = User::factory()->create();
        $outsider = User::factory()->create();
        $document = Document::create([
            'id' => (string) Str::uuid(), 'verification_code' => Str::random(48), 'requester_id' => $requester->id,
            'title' => 'Document privé', 'status' => 'pending', 'original_name' => 'prive.pdf',
            'file_path' => 'documents/private/current.pdf', 'file_size' => 100, 'page_count' => 1,
            'sha256' => str_repeat('a', 64), 'expires_at' => now()->addDay(),
        ]);
        SignatureRequest::create(['document_id' => $document->id, 'signer_email' => 'signataire@acarts.be']);

        $this->actingAs($outsider)->get(route('documents.show', $document))->assertForbidden();
        $this->actingAs($outsider)->get(route('documents.file', $document))->assertForbidden();
    }

    private function compressedPdfBytes(): string
    {
        $source = tempnam(sys_get_temp_dir(), 'pdf-');
        $target = $source.'-xref-stream.pdf';
        file_put_contents($source, $this->pdfBytes());
        Process::run(['qpdf', '--object-streams=generate', $source, $target])->throw();
        $bytes = file_get_contents($target);
        @unlink($source);
        @unlink($target);

        return $bytes;
    }

    private function pdfBytes(): string
    {
        $pdf = new \TCPDF;
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Write(0, 'Document de test');

        return $pdf->Output('', 'S');
    }
}
