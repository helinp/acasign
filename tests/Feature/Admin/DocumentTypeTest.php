<?php

namespace Tests\Feature\Admin;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentTypeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_default_document_types_are_available(): void
    {
        $this->assertEqualsCanonicalizing(['Doc12', 'Grève', 'CAD'], DocumentType::active()->pluck('name')->all());
    }

    public function test_admin_can_add_and_deactivate_a_document_type(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.document-types.store'), ['name' => 'Attestation'])->assertSessionHasNoErrors();
        $type = DocumentType::where('name', 'Attestation')->sole();
        $this->assertTrue($type->is_active);

        $this->actingAs($admin)->put(route('admin.document-types.update', $type), ['name' => 'Attestation'])->assertSessionHasNoErrors();
        $this->assertFalse($type->fresh()->is_active);
    }

    public function test_duplicate_document_type_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.document-types.store'), ['name' => 'CAD'])->assertSessionHasErrors('name');
    }

    public function test_non_admin_cannot_manage_document_types(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)->get(route('admin.document-types.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.document-types.store'), ['name' => 'Pirate'])->assertForbidden();
        $this->assertDatabaseMissing('document_types', ['name' => 'Pirate']);
    }

    public function test_create_form_lists_active_types_and_known_users_without_hr_reference(): void
    {
        DocumentType::where('name', 'CAD')->update(['is_active' => false]);
        $sender = User::factory()->create(['can_send' => true]);
        User::factory()->create(['email' => 'prof@acarts.be']);
        User::factory()->create(['email' => 'ancien@acarts.be', 'disabled_at' => now()]);

        $this->actingAs($sender)->get(route('documents.create'))
            ->assertOk()
            ->assertSee('Doc12')
            ->assertDontSee('<option value="CAD"', false)
            ->assertSee('prof@acarts.be')
            ->assertDontSee('ancien@acarts.be')
            ->assertDontSee('Référence RH');
    }

    public function test_inactive_document_type_is_refused_on_creation(): void
    {
        DocumentType::where('name', 'CAD')->update(['is_active' => false]);
        $sender = User::factory()->create(['can_send' => true]);

        $this->actingAs($sender)->post(route('documents.store'), ['title' => 'Test', 'document_type' => 'CAD', 'signers' => ['prof@acarts.be']])
            ->assertSessionHasErrors('document_type');
    }

    public function test_unused_document_type_can_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $type = DocumentType::where('name', 'CAD')->sole();

        $this->actingAs($admin)->delete(route('admin.document-types.destroy', $type))->assertSessionHasNoErrors();

        $this->assertModelMissing($type);
    }

    public function test_used_document_type_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->documentOfType($admin, 'Grève');
        $type = DocumentType::where('name', 'Grève')->sole();

        $this->actingAs($admin)->delete(route('admin.document-types.destroy', $type))->assertSessionHasErrors('type');

        $this->assertModelExists($type);
    }

    public function test_renaming_a_type_updates_existing_documents(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $document = $this->documentOfType($admin, 'Grève');
        $type = DocumentType::where('name', 'Grève')->sole();

        $this->actingAs($admin)->put(route('admin.document-types.update', $type), ['name' => 'Préavis de grève', 'is_active' => 1])->assertSessionHasNoErrors();

        $this->assertSame('Préavis de grève', $document->fresh()->document_type);
    }

    private function documentOfType(User $requester, string $type): Document
    {
        return Document::create([
            'id' => (string) Str::uuid(), 'verification_code' => Str::random(48), 'requester_id' => $requester->id,
            'title' => 'Document', 'status' => 'pending', 'original_name' => 'document.pdf', 'document_type' => $type,
            'file_path' => 'documents/x/current.pdf', 'file_size' => 100, 'page_count' => 1,
            'sha256' => str_repeat('a', 64), 'expires_at' => now()->addDay(),
        ]);
    }
}
