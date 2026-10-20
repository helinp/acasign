<?php

namespace Tests\Feature;

use App\Jobs\DispatchDocumentNotification;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentBatchTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([DispatchDocumentNotification::class]);
        Storage::fake('local');
        config(['services.entra.allowed_domains' => ['ecole.test']]);
    }

    public function test_several_pdfs_create_one_document_each_in_the_same_batch(): void
    {
        $sender = User::factory()->create(['email' => 'rh@ecole.test', 'can_send' => true]);

        $response = $this->actingAs($sender)->post(route('documents.store'), [
            'title' => 'Grève 12 mars',
            'document_type' => 'Grève',
            'documents' => [$this->pdf('equipe-a.pdf'), $this->pdf('equipe-b.pdf')],
            'signers' => ['prof@ecole.test'],
        ]);

        $documents = Document::with('signatureRequests')->orderBy('title')->get();
        $this->assertCount(2, $documents);
        $this->assertSame(['Grève 12 mars — equipe-a', 'Grève 12 mars — equipe-b'], $documents->pluck('title')->all());
        $this->assertNotNull($documents[0]->batch_id);
        $this->assertSame($documents[0]->batch_id, $documents[1]->batch_id);
        $this->assertSame(['prof@ecole.test'], $documents[1]->signatureRequests->pluck('signer_email')->all());
        $this->assertNotEmpty($documents[0]->page_sizes);
        $response->assertRedirect(route('documents.edit', $documents[0]));

        $this->actingAs($sender)->get(route('documents.edit', $documents[0]))->assertOk()->assertSee('Lot · document');
    }

    public function test_requester_can_sign_alone(): void
    {
        $sender = User::factory()->create(['email' => 'direction@ecole.test', 'can_send' => true]);

        $this->actingAs($sender)->post(route('documents.store'), [
            'title' => 'Attestation', 'document_type' => 'Doc12', 'documents' => [$this->pdf('attestation.pdf')], 'self_sign' => 1, 'signers' => [null],
        ])->assertSessionHasNoErrors();

        $document = Document::with('signatureRequests')->sole();
        $this->assertSame(['direction@ecole.test'], $document->signatureRequests->pluck('signer_email')->all());
        $this->actingAs($sender)->get(route('documents.edit', $document))
            ->assertSee(route('signatures.show', $document->signatureRequests->first()), false)
            ->assertSee('Terminer et signer');
    }

    public function test_at_least_one_signer_is_required(): void
    {
        $sender = User::factory()->create(['can_send' => true]);

        $this->actingAs($sender)->post(route('documents.store'), [
            'title' => 'Sans signataire', 'document_type' => 'Doc12', 'documents' => [$this->pdf('a.pdf')], 'signers' => [null],
        ])->assertSessionHasErrors('signers');

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_document_type_requiring_eid_switches_signature_method(): void
    {
        DocumentType::where('name', 'CAD')->update(['requires_eid' => true]);
        $sender = User::factory()->create(['email' => 'rh@ecole.test', 'can_send' => true]);

        $this->actingAs($sender)->post(route('documents.store'), [
            'title' => 'CAD', 'document_type' => 'CAD', 'documents' => [$this->pdf('cad.pdf')], 'self_sign' => 1,
        ])->assertSessionHasNoErrors();

        $document = Document::with('signatureRequests')->sole();
        $this->assertTrue($document->usesEid());
        $signatureRequest = $document->signatureRequests->first();
        $sender->update(['signature_path' => 'signatures/x.enc']);

        $this->actingAs($sender)->post(route('signatures.sign', $signatureRequest), ['show_name' => 1])->assertStatus(409);
        $this->actingAs($sender)->postJson(route('documents.fields.store', $document), ['type' => 'text', 'signature_request_id' => $signatureRequest->id, 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'width' => 0.2, 'height' => 0.03])->assertStatus(422);
        $this->actingAs($sender)->get(route('signatures.show', $signatureRequest))->assertOk()->assertSee('data-eid-sign', false)->assertSee('data-eid-help="auto"', false);
    }

    private function pdf(string $name): UploadedFile
    {
        $pdf = new \TCPDF;
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->Write(0, $name);

        return UploadedFile::fake()->createWithContent($name, $pdf->Output('', 'S'));
    }
}
