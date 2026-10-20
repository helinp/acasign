<?php

namespace App\Http\Controllers;

use App\Http\Requests\DocumentFilterRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Jobs\DispatchDocumentNotification;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Services\AuditService;
use App\Services\InternalIdentityService;
use App\Services\PdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class DocumentController extends Controller
{
    /** Nombre maximal de PDF dans un téléchargement groupé. */
    private const ARCHIVE_LIMIT = 200;

    /**
     * Documents dont l'utilisateur est demandeur ou signataire, par onglet (signés, en cours, clos),
     * avec recherche (titre, personne), période, type, rôle et tri — utilisable avec des centaines de documents.
     */
    public function index(DocumentFilterRequest $request): View
    {
        $filters = $request->validated();
        $tab = $request->tab();
        $dateColumn = self::dateColumn($tab);
        // Mêmes filtres pour la liste et les compteurs des onglets (chaque onglet filtre sur sa propre date).
        $filtered = fn (string $tabKey) => Document::query()
            ->involving($request->user(), $filters['role'] ?? null)
            ->filtered($filters, self::dateColumn($tabKey))
            ->whereIn('status', DocumentFilterRequest::TABS[$tabKey]);

        return view('documents.index', [
            'documents' => $filtered($tab)->with(['requester', 'signatureRequests'])->sorted($request->sort(), $dateColumn)->paginate($request->perPage())->withQueryString(),
            'filters' => $filters,
            'activeFilters' => $request->activeFilters(),
            'tab' => $tab,
            'sort' => $request->sort(),
            'perPage' => $request->perPage(),
            'counts' => collect(DocumentFilterRequest::TABS)->map(fn (array $statuses, string $tabKey) => $filtered($tabKey)->count()),
            'documentTypes' => DocumentType::orderBy('name')->pluck('name'),
            'knownPeople' => $this->knownPeople($request->user()),
        ]);
    }

    /**
     * Téléchargement groupé (ZIP) des PDF signés : la sélection cochée, ou à défaut tous les documents signés filtrés (200 max.).
     */
    public function archive(DocumentFilterRequest $request, AuditService $audit): BinaryFileResponse|RedirectResponse
    {
        $ids = $request->validate(['documents' => ['nullable', 'array', 'max:'.self::ARCHIVE_LIMIT], 'documents.*' => ['uuid']])['documents'] ?? [];
        $documents = Document::query()
            ->involving($request->user(), $request->validated('role'))
            ->where('status', 'signed')
            ->when($ids, fn ($query) => $query->whereIn('id', $ids), fn ($query) => $query->filtered($request->validated(), 'completed_at'))
            ->sorted($request->sort(), 'completed_at')
            ->limit(self::ARCHIVE_LIMIT)
            ->get()
            ->filter(fn (Document $document) => Storage::disk('local')->exists($document->file_path));

        if ($documents->isEmpty()) {
            return back()->withErrors(['documents' => 'Aucun PDF signé à télécharger pour cette sélection.']);
        }

        $path = tempnam(sys_get_temp_dir(), 'signes-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $names = [];
        foreach ($documents as $document) {
            $name = $document->downloadName();
            $names[$name] = ($names[$name] ?? 0) + 1;
            $zip->addFile(Storage::disk('local')->path($document->file_path), $names[$name] > 1 ? pathinfo($name, PATHINFO_FILENAME).'-'.$names[$name].'.pdf' : $name);
            $audit->record('document.downloaded', $document, $request->user(), ['archive' => true], $request);
        }
        $zip->close();

        return response()->download($path, 'documents-signes-'.now()->format('Y-m-d').'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /** Colonne de date d'un onglet : date de signature complète pour les signés, de création sinon. */
    private static function dateColumn(string $tab): string
    {
        return $tab === 'signed' ? 'completed_at' : 'created_at';
    }

    /**
     * Demandeurs et signataires déjà croisés par l'utilisateur, proposés dans le filtre « Personne ».
     *
     * @return Collection<int, string>
     */
    private function knownPeople(User $user): Collection
    {
        $documentIds = Document::query()->involving($user)->select('id');

        return SignatureRequest::query()->whereIn('document_id', $documentIds)->distinct()->pluck('signer_name')
            ->merge(User::query()->whereIn('id', Document::query()->involving($user)->select('requester_id'))->pluck('name'))
            ->filter()->unique()->sort()->values();
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('documents.create', [
            'documentTypes' => DocumentType::active()->orderBy('name')->get(),
            'knownUsers' => User::knownForSigning(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreDocumentRequest $request, PdfService $pdf, InternalIdentityService $identities, AuditService $audit): RedirectResponse
    {
        $data = $request->validated();
        $signers = collect($request->signerEmails())
            ->when($request->boolean('self_sign'), fn ($emails) => $emails->prepend($request->user()->email))
            ->map(fn ($email) => $identities->normalizeAndValidate($email))
            ->unique()->values();
        $files = $request->pdfFiles();
        $batchId = count($files) > 1 ? (string) Str::uuid() : null;
        $method = DocumentType::where('name', $data['document_type'])->value('requires_eid') ? 'eid' : 'internal';
        $created = [];

        try {
            foreach ($files as $file) {
                $title = $batchId ? $data['title'].' — '.pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) : $data['title'];
                $created[] = $this->createDocument($request, $pdf, $file, $signers, [...$data, 'title' => $title], $batchId, $method);
            }
        } catch (\Throwable $e) {
            foreach ($created as $document) {
                Storage::disk('local')->deleteDirectory(dirname($document->file_path));
                $document->delete();
            }
            throw $e;
        }

        foreach ($created as $document) {
            $audit->record('document.created', $document, $request->user(), ['signers' => $signers->all(), 'sha256' => $document->sha256, 'batch_id' => $batchId], $request);
            DispatchDocumentNotification::dispatch($document->id, 'document.requested');
        }

        $message = count($created) > 1
            ? count($created).' documents créés. Positionnez les signatures de chacun.'
            : 'Document créé. Positionnez maintenant les zones de signature.';

        return redirect()->route('documents.edit', $created[0])->with('success', $message);
    }

    /**
     * Assainit un PDF et crée le document et ses demandes de signature.
     *
     * @param  Collection<int, string>  $signers
     * @param  array<string, mixed>  $data
     */
    private function createDocument(Request $request, PdfService $pdf, UploadedFile $file, Collection $signers, array $data, ?string $batchId, string $method): Document
    {
        $id = (string) Str::uuid();
        $relativePath = "documents/$id/current.pdf";
        $absolutePath = Storage::disk('local')->path($relativePath);

        try {
            $pages = $pdf->sanitize($file->getRealPath(), $absolutePath);

            return DB::transaction(function () use ($data, $request, $file, $signers, $id, $relativePath, $absolutePath, $pages, $pdf, $batchId, $method) {
                $document = Document::create([
                    'id' => $id,
                    'batch_id' => $batchId,
                    'verification_code' => Str::random(48),
                    'requester_id' => $request->user()->id,
                    'title' => Str::limit($data['title'], 180, ''),
                    'document_type' => $data['document_type'] ?? null,
                    'signature_method' => $method,
                    'signer_can_move' => (bool) ($data['signer_can_move'] ?? false),
                    'status' => 'pending',
                    'original_name' => $file->getClientOriginalName(),
                    'file_path' => $relativePath,
                    'file_size' => filesize($absolutePath),
                    'page_count' => $pages,
                    'page_sizes' => $pdf->pageSizes($absolutePath),
                    'sha256' => hash_file('sha256', $absolutePath),
                    'expires_at' => $data['expires_at'] ?? now()->addDays(15),
                ]);
                foreach ($signers as $index => $email) {
                    $known = User::whereRaw('LOWER(email) = ?', [$email])->first();
                    SignatureRequest::create([
                        'document_id' => $document->id,
                        'signer_id' => $known?->id,
                        'signer_email' => $email,
                        'signer_name' => $known?->signatureName() ?? $email,
                        'page' => $pages,
                        'position_x' => 0.62,
                        'position_y' => max(0.05, 0.80 - ($index * 0.14)),
                        'display_options' => ['show_name' => true, 'show_date' => true, 'show_job_title' => false],
                    ]);
                }

                return $document;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($relativePath);
            throw $e;
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Document $document, AuditService $audit): View
    {
        $this->authorizeMetadata($request, $document);
        $document->load(['requester', 'signatureRequests.signer', 'auditEvents.actor']);
        $audit->record('document.viewed', $document, $request->user(), [], $request);

        return view('documents.show', compact('document'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Document $document): View
    {
        abort_unless($document->requester_id === $request->user()->id && $document->status === 'pending', 403);
        $document->load(['signatureRequests', 'fields.signatureRequest']);
        $batch = $document->batch_id ? $document->batchSiblings()->get(['id', 'title', 'batch_id', 'created_at']) : collect([$document]);
        $position = $batch->search(fn (Document $sibling) => $sibling->id === $document->id);
        $next = $batch->get($position + 1);
        $mySignature = SignatureRequest::query()
            ->whereIn('document_id', $batch->pluck('id'))
            ->where('signer_email', mb_strtolower($request->user()->email))
            ->where('status', 'pending')
            ->orderByRaw('(select created_at from documents where documents.id = signature_requests.document_id)')
            ->first();

        return view('documents.editor', [
            'document' => $document,
            'batch' => $batch,
            'position' => $position,
            'nextUrl' => $next ? route('documents.edit', $next) : null,
            'finishUrl' => $mySignature ? route('signatures.show', $mySignature) : route('documents.show', $document),
            'finishLabel' => $mySignature ? 'Terminer et signer' : 'Terminer',
            'finishIcon' => $mySignature ? 'signature' : 'check',
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Document $document, AuditService $audit): JsonResponse
    {
        abort_unless($document->requester_id === $request->user()->id && $document->status === 'pending', 403);
        $data = $request->validate([
            'placements' => ['required', 'array'],
            'placements.*.id' => ['required', 'uuid'],
            'placements.*.page' => ['required', 'integer', 'min:1', 'max:'.$document->page_count],
            'placements.*.x' => ['required', 'numeric', 'between:0,0.95'],
            'placements.*.y' => ['required', 'numeric', 'between:0,0.95'],
            'placements.*.width' => ['required', 'numeric', 'between:0.08,0.8'],
            'placements.*.height' => ['required', 'numeric', 'between:0.04,0.4'],
        ]);
        foreach ($data['placements'] as $placement) {
            $document->signatureRequests()->whereKey($placement['id'])->update([
                'page' => $placement['page'], 'position_x' => $placement['x'], 'position_y' => $placement['y'],
                'width' => $placement['width'], 'height' => $placement['height'],
            ]);
        }
        $audit->record('document.placements.updated', $document, $request->user(), ['count' => count($data['placements'])], $request);

        return response()->json(['message' => 'Emplacements enregistrés.']);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Document $document, AuditService $audit): RedirectResponse
    {
        abort_unless($document->requester_id === $request->user()->id && ! $document->isTerminal(), 403);
        $document->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $audit->record('document.cancelled', $document, $request->user(), [], $request);
        DispatchDocumentNotification::dispatch($document->id, 'document.cancelled');

        return back()->with('success', 'La demande a été annulée.');
    }

    public function file(Request $request, Document $document, AuditService $audit): StreamedResponse
    {
        $this->authorizeContent($request, $document);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);
        $audit->record('document.downloaded', $document, $request->user(), [], $request);

        $disk = Storage::disk('local');

        return $request->boolean('download')
            ? $disk->download($document->file_path, $document->downloadName(), ['Content-Type' => 'application/pdf'])
            : $disk->response($document->file_path, $document->downloadName(), ['Content-Type' => 'application/pdf']);
    }

    public function revoke(Request $request, Document $document, AuditService $audit): RedirectResponse
    {
        abort_unless($document->requester_id === $request->user()->id && $document->status === 'signed', 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $document->update(['status' => 'revoked', 'revoked_at' => now(), 'revocation_reason' => $data['reason']]);
        $audit->record('document.revoked', $document, $request->user(), ['reason' => $data['reason']], $request);
        DispatchDocumentNotification::dispatch($document->id, 'document.revoked');

        return back()->with('success', 'Le document est désormais signalé comme révoqué.');
    }

    /**
     * Export CSV (séparateur « ; », compatible Excel) de la liste telle qu'elle est filtrée et triée à l'écran.
     */
    public function export(DocumentFilterRequest $request): StreamedResponse
    {
        $tab = $request->tab();
        $documents = Document::query()
            ->involving($request->user(), $request->validated('role'))
            ->filtered($request->validated(), self::dateColumn($tab))
            ->whereIn('status', DocumentFilterRequest::TABS[$tab])
            ->with(['requester', 'signatureRequests'])
            ->sorted($request->sort(), self::dateColumn($tab))
            ->get();
        $labels = ['pending' => 'En attente', 'signed' => 'Signé', 'rejected' => 'Refusé', 'cancelled' => 'Annulé', 'expired' => 'Expiré', 'revoked' => 'Révoqué'];

        return response()->streamDownload(function () use ($documents, $labels) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Titre', 'Type', 'Statut', 'Demandeur', 'Signataires', 'Créé le', 'Signé le', 'Référence'], ';');
            foreach ($documents as $document) {
                fputcsv($out, [
                    $document->title, $document->document_type, $labels[$document->status] ?? $document->status, $document->requester?->name,
                    $document->signatureRequests->pluck('signer_name')->join(', '), $document->created_at?->format('d/m/Y H:i'),
                    $document->completed_at?->format('d/m/Y H:i'), $document->id,
                ], ';');
            }
            fclose($out);
        }, 'documents-'.$tab.'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizeMetadata(Request $request, Document $document): void
    {
        $participant = $document->requester_id === $request->user()->id ||
            $document->signatureRequests()->where('signer_email', mb_strtolower($request->user()->email))->exists();
        abort_unless($participant || $request->user()->isAdmin(), 403);
    }

    private function authorizeContent(Request $request, Document $document): void
    {
        $participant = $document->requester_id === $request->user()->id ||
            $document->signatureRequests()->where('signer_email', mb_strtolower($request->user()->email))->exists();
        abort_unless($participant, 403);
    }
}
