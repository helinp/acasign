<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentField;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Services\PdfService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentFieldTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const GEOMETRY = ['page' => 1, 'x' => 0.1, 'y' => 0.2, 'width' => 0.16, 'height' => 0.03];

    public function test_requester_places_a_required_field_for_a_signer(): void
    {
        [$requester, $signer, $document, $signatureRequest] = $this->pendingDocument();

        $this->actingAs($requester)->postJson(route('documents.fields.store', $document), [
            'type' => 'date', 'signature_request_id' => $signatureRequest->id, 'label' => 'Date d’effet', 'required' => true, ...self::GEOMETRY,
        ])->assertCreated()->assertJsonPath('type', 'date')->assertJsonPath('required', true);

        $this->assertDatabaseHas('document_fields', ['signature_request_id' => $signatureRequest->id, 'type' => 'date', 'required' => true, 'added_by_signer' => false]);
    }

    public function test_signer_cannot_place_fields_on_the_requester_side(): void
    {
        [, $signer, $document, $signatureRequest] = $this->pendingDocument();

        $this->actingAs($signer)->postJson(route('documents.fields.store', $document), [
            'type' => 'text', 'signature_request_id' => $signatureRequest->id, ...self::GEOMETRY,
        ])->assertForbidden();
    }

    public function test_signer_adds_and_removes_own_field_but_not_requester_fields(): void
    {
        [, $signer, $document, $signatureRequest] = $this->pendingDocument();
        $requesterField = $this->field($document, $signatureRequest, ['type' => 'text']);

        $own = $this->actingAs($signer)->postJson(route('signatures.fields.store', $signatureRequest), ['type' => 'checkbox', ...self::GEOMETRY])
            ->assertCreated()->json('id');

        $this->actingAs($signer)->deleteJson(route('signatures.fields.destroy', [$signatureRequest, $requesterField]))->assertNotFound();
        $this->actingAs($signer)->deleteJson(route('signatures.fields.destroy', [$signatureRequest, $own]))->assertNoContent();
        $this->assertDatabaseHas('document_fields', ['id' => $requesterField->id]);
        $this->assertDatabaseMissing('document_fields', ['id' => $own]);
    }

    public function test_signing_is_blocked_while_a_required_field_is_empty(): void
    {
        [, $signer, $document, $signatureRequest] = $this->pendingDocument();
        $signer->update(['signature_path' => 'signatures/test.enc']);
        $field = $this->field($document, $signatureRequest, ['type' => 'text', 'required' => true]);

        $this->actingAs($signer)->post(route('signatures.sign', $signatureRequest), ['show_name' => 1, 'consent' => 1, 'fields' => [$field->id => '']])
            ->assertSessionHasErrors("fields.{$field->id}");

        $this->assertSame('pending', $signatureRequest->fresh()->status);
    }

    public function test_required_checkbox_must_be_ticked(): void
    {
        [, $signer, $document, $signatureRequest] = $this->pendingDocument();
        $signer->update(['signature_path' => 'signatures/test.enc']);
        $field = $this->field($document, $signatureRequest, ['type' => 'checkbox', 'required' => true]);

        $this->actingAs($signer)->post(route('signatures.sign', $signatureRequest), ['show_name' => 1, 'consent' => 1])
            ->assertSessionHasErrors("fields.{$field->id}");
    }

    public function test_signing_requires_reading_confirmation(): void
    {
        [, $signer, , $signatureRequest] = $this->pendingDocument();
        $signer->update(['signature_path' => 'signatures/test.enc']);

        $this->actingAs($signer)->post(route('signatures.sign', $signatureRequest), ['show_name' => 1])
            ->assertSessionHasErrors('consent');
        $this->assertSame('pending', $signatureRequest->fresh()->status);
    }

    public function test_printable_values_are_formatted(): void
    {
        $this->assertSame('19/10/2026', (new DocumentField(['type' => 'date', 'value' => '2026-10-19']))->printableValue());
        $this->assertSame('1', (new DocumentField(['type' => 'checkbox', 'value' => '1']))->printableValue());
        $this->assertNull((new DocumentField(['type' => 'checkbox', 'value' => '0']))->printableValue());
        $this->assertNull((new DocumentField(['type' => 'text', 'value' => null]))->printableValue());
    }

    public function test_stamp_prints_completed_fields_into_the_pdf(): void
    {
        Storage::fake('local');
        [, , $document, $signatureRequest] = $this->pendingDocument();
        Storage::disk('local')->put($document->file_path, $this->pdfBytes());
        $before = Storage::disk('local')->get($document->file_path);
        $this->field($document, $signatureRequest, ['type' => 'date', 'value' => '2026-10-19']);
        $this->field($document, $signatureRequest, ['type' => 'checkbox', 'value' => '1']);
        $this->field($document, $signatureRequest, ['type' => 'text', 'value' => 'Lu et approuvé']);

        $sha = app(PdfService::class)->stamp($document, $signatureRequest->fresh(), $this->pngBytes());

        $this->assertSame(64, strlen($sha));
        $this->assertNotSame($before, Storage::disk('local')->get($document->file_path));
    }

    /**
     * @return array{0: User, 1: User, 2: Document, 3: SignatureRequest}
     */
    private function pendingDocument(): array
    {
        $requester = User::factory()->create(['can_send' => true]);
        $signer = User::factory()->create(['email' => 'signataire@acarts.be']);
        $document = Document::create([
            'id' => (string) Str::uuid(), 'verification_code' => Str::random(48), 'requester_id' => $requester->id,
            'title' => 'Formulaire', 'status' => 'pending', 'original_name' => 'formulaire.pdf',
            'file_path' => 'documents/formulaire/current.pdf', 'file_size' => 100, 'page_count' => 1,
            'sha256' => str_repeat('a', 64), 'expires_at' => now()->addDay(),
        ]);
        $signatureRequest = SignatureRequest::create(['document_id' => $document->id, 'signer_id' => $signer->id, 'signer_email' => $signer->email, 'signer_name' => $signer->name]);

        return [$requester, $signer, $document, $signatureRequest];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function field(Document $document, SignatureRequest $signatureRequest, array $attributes): DocumentField
    {
        return DocumentField::create([
            'document_id' => $document->id, 'signature_request_id' => $signatureRequest->id,
            'page' => 1, 'position_x' => 0.1, 'position_y' => 0.2, 'width' => 0.16, 'height' => 0.03,
            ...$attributes,
        ]);
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
