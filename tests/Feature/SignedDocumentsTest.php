<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SignedDocumentsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_requester_and_signer_see_signed_documents_but_not_others(): void
    {
        $requester = User::factory()->create();
        $signer = User::factory()->create(['email' => 'prof@ecole.test']);
        $outsider = User::factory()->create();
        $signed = $this->document($requester, 'Grève du 12 mars', 'signed', $signer);
        $this->document($requester, 'Document en cours', 'pending', $signer);

        $this->actingAs($requester)->get(route('documents.index'))->assertOk()->assertSee('Grève du 12 mars')->assertDontSee('Document en cours');
        $this->actingAs($signer)->get(route('documents.index'))->assertOk()->assertSee('Grève du 12 mars');
        $this->actingAs($signer)->get(route('documents.index', ['role' => 'requester']))->assertDontSee('Grève du 12 mars');
        $this->actingAs($outsider)->get(route('documents.index'))->assertOk()->assertDontSee($signed->title);
    }

    public function test_pending_tab_lists_documents_in_progress(): void
    {
        $requester = User::factory()->create();
        $this->document($requester, 'Document en cours', 'pending');
        $this->document($requester, 'Contrat signé', 'signed');

        $this->actingAs($requester)->get(route('documents.index', ['status' => 'pending']))
            ->assertOk()->assertSee('Document en cours')->assertDontSee('Contrat signé');
    }

    public function test_tabs_show_counts_and_title_sort(): void
    {
        $requester = User::factory()->create();
        $this->document($requester, 'Zèbre', 'signed');
        $this->document($requester, 'Avenant', 'signed');
        $this->document($requester, 'En attente', 'pending');

        $response = $this->actingAs($requester)->get(route('documents.index', ['sort' => 'title']))->assertOk();

        $response->assertSeeInOrder(['Signés', '2', 'En cours', '1']);
        $response->assertSeeInOrder(['Avenant', 'Zèbre']);
    }

    public function test_search_ignores_case(): void
    {
        $requester = User::factory()->create();
        $this->document($requester, 'Préavis de GRÈVE', 'signed');

        $this->actingAs($requester)->get(route('documents.index', ['q' => 'préavis']))->assertSee('Préavis de GRÈVE');
    }

    public function test_journal_shows_readable_labels_and_folds_consultations(): void
    {
        $requester = User::factory()->create();
        $document = $this->document($requester, 'Contrat', 'signed');
        $audit = app(AuditService::class);
        $audit->record('signature.signed', $document, $requester, ['method' => 'eid']);
        $audit->record('document.viewed', $document, $requester);

        $this->actingAs($requester)->get(route('documents.show', $document))
            ->assertSee('Signé avec la carte eID')
            ->assertSee('Consultations et téléchargements (1)')
            ->assertDontSee('signature · signed');
    }

    public function test_signed_document_downloads_with_signed_suffix(): void
    {
        Storage::fake('local');
        $requester = User::factory()->create();
        $document = $this->document($requester, 'Contrat', 'signed');
        Storage::disk('local')->put($document->file_path, '%PDF-1.4');

        $response = $this->actingAs($requester)->get(route('documents.file', [$document, 'download' => 1]));

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString(rawurlencode('contrat-2026-signé.pdf'), $response->headers->get('Content-Disposition'));
    }

    public function test_pending_document_keeps_original_name(): void
    {
        $this->assertSame('contrat-2026.pdf', (new Document(['original_name' => 'contrat-2026.pdf', 'status' => 'pending']))->downloadName());
        $this->assertSame('contrat-2026-signé.pdf', (new Document(['original_name' => 'contrat-2026.pdf', 'status' => 'signed']))->downloadName());
    }

    public function test_person_filter_matches_signer_name_or_email(): void
    {
        $requester = User::factory()->create();
        $this->document($requester, 'Contrat Dupont', 'signed', User::factory()->create(['name' => 'Marie Dupont', 'email' => 'marie.dupont@ecole.test']));
        $this->document($requester, 'Contrat Martin', 'signed', User::factory()->create(['name' => 'Luc Martin', 'email' => 'luc.martin@ecole.test']));

        $this->actingAs($requester)->get(route('documents.index', ['person' => 'dupont']))
            ->assertOk()->assertSee('Contrat Dupont')->assertDontSee('Contrat Martin');
    }

    public function test_period_and_date_range_filter_on_signature_date(): void
    {
        $requester = User::factory()->create();
        $this->document($requester, 'Signé hier', 'signed')->update(['completed_at' => now()->subDay()]);
        $this->document($requester, 'Signé il y a deux mois', 'signed')->update(['completed_at' => now()->subMonths(2)]);

        $this->actingAs($requester)->get(route('documents.index', ['period' => 30]))
            ->assertOk()->assertSee('Signé hier')->assertDontSee('Signé il y a deux mois');
        $this->actingAs($requester)->get(route('documents.index', ['from' => now()->subMonths(3)->format('Y-m-d'), 'to' => now()->subMonth()->format('Y-m-d')]))
            ->assertOk()->assertSee('Signé il y a deux mois')->assertDontSee('Signé hier');
    }

    public function test_invalid_date_range_is_rejected(): void
    {
        $requester = User::factory()->create();

        $this->actingAs($requester)->get(route('documents.index', ['from' => '2026-05-10', 'to' => '2026-05-01']))->assertSessionHasErrors('to');
    }

    public function test_selected_signed_documents_download_as_zip(): void
    {
        Storage::fake('local');
        $requester = User::factory()->create();
        $first = $this->document($requester, 'Premier', 'signed');
        $second = $this->document($requester, 'Second', 'signed');
        $other = $this->document(User::factory()->create(), 'Pas à moi', 'signed');
        foreach ([$first, $second, $other] as $document) {
            Storage::disk('local')->put($document->file_path, '%PDF-1.4');
        }

        $response = $this->actingAs($requester)->post(route('documents.archive'), ['documents' => [$first->id, $second->id, $other->id]]);

        $response->assertOk()->assertDownload('documents-signes-'.now()->format('Y-m-d').'.zip');
        $zip = new \ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $this->assertSame(2, $zip->numFiles);
        $this->assertSame(['contrat-2026-signé-2.pdf', 'contrat-2026-signé.pdf'], collect(range(0, 1))->map(fn (int $index) => $zip->getNameIndex($index))->sort()->values()->all());
        $zip->close();
    }

    public function test_zip_download_without_signed_documents_returns_an_error(): void
    {
        $requester = User::factory()->create();

        $this->actingAs($requester)->from(route('documents.index'))->post(route('documents.archive'), ['q' => 'introuvable'])
            ->assertRedirect(route('documents.index'))->assertSessionHasErrors('documents');
    }

    public function test_csv_export_follows_list_filters(): void
    {
        $requester = User::factory()->create(['name' => 'Anne Demandeuse']);
        $this->document($requester, 'Préavis de grève', 'signed');
        $this->document($requester, 'Contrat', 'signed');

        $csv = $this->actingAs($requester)->get(route('documents.export', ['q' => 'préavis']))->assertOk()->streamedContent();

        $this->assertStringContainsString('Titre;Type;Statut;Demandeur', $csv);
        $this->assertStringContainsString('"Préavis de grève";Grève;Signé;"Anne Demandeuse"', $csv);
        $this->assertStringNotContainsString('Contrat', $csv);
    }

    public function test_dashboard_lists_latest_signed_documents_and_can_show_more(): void
    {
        $requester = User::factory()->create();
        foreach (range(1, 6) as $day) {
            $this->document($requester, "Signé J-$day", 'signed')->update(['completed_at' => now()->subDays($day)]);
        }
        $this->document(User::factory()->create(), 'Document d’un autre', 'signed');

        $this->actingAs($requester)->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['Derniers documents signés', 'Signé J-1', 'Signé J-5'])
            ->assertDontSee('Signé J-6')->assertDontSee('Document d’un autre');
        $this->actingAs($requester)->get(route('dashboard', ['recent' => 10]))->assertOk()->assertSee('Signé J-6');
    }

    private function document(User $requester, string $title, string $status, ?User $signer = null): Document
    {
        $document = Document::create([
            'id' => (string) Str::uuid(), 'verification_code' => Str::random(48), 'requester_id' => $requester->id,
            'title' => $title, 'status' => $status, 'original_name' => 'contrat-2026.pdf', 'document_type' => 'Grève',
            'file_path' => 'documents/'.Str::uuid().'/current.pdf', 'file_size' => 100, 'page_count' => 1,
            'sha256' => str_repeat('a', 64), 'expires_at' => now()->addDay(), 'completed_at' => $status === 'signed' ? now() : null,
        ]);
        if ($signer) {
            SignatureRequest::create([
                'document_id' => $document->id, 'signer_id' => $signer->id, 'signer_email' => $signer->email,
                'signer_name' => $signer->name, 'status' => $status === 'signed' ? 'signed' : 'pending',
            ]);
        }

        return $document;
    }
}
