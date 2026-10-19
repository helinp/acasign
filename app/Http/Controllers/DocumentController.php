<?php

namespace App\Http\Controllers;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    /**
     * Statuts regroupés par onglet de la liste des documents.
     *
     * @var array<string, list<string>>
     */
    private const INDEX_TABS = [
        'signed' => ['signed'],
        'pending' => ['pending'],
        'closed' => ['rejected', 'cancelled', 'expired', 'revoked'],
    ];

    /**
     * Documents dont l'utilisateur est demandeur ou signataire, par onglet (signés, en cours, clos).
     */
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'max:80'],
            'role' => ['nullable', 'in:requester,signer'],
            'status' => ['nullable', 'in:'.implode(',', array_keys(self::INDEX_TABS))],
        ]);
        $tab = $filters['status'] ?? 'signed';
        $user = $request->user();
        $email = mb_strtolower($user->email);
        $involved = fn ($query) => $query->where('signer_email', $email);

        $documents = Document::query()
            ->with(['requester', 'signatureRequests'])
            ->whereIn('status', self::INDEX_TABS[$tab])
            ->where(fn ($query) => match ($filters['role'] ?? null) {
                'requester' => $query->where('requester_id', $user->id),
                'signer' => $query->whereHas('signatureRequests', $involved),
                default => $query->where('requester_id', $user->id)->orWhereHas('signatureRequests', $involved),
            })
            ->when($filters['q'] ?? null, fn ($query, string $q) => $query->where('title', 'like', '%'.$q.'%'))
            ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('document_type', $type))
            ->orderByDesc($tab === 'signed' ? 'completed_at' : 'created_at')
            ->paginate(25)
            ->withQueryString();

        return view('documents.index', [
            'documents' => $documents,
            'filters' => $filters,
            'tab' => $tab,
            'documentTypes' => DocumentType::orderBy('name')->pluck('name'),
        ]);
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
        $signers = collect($data['signers'])->map(fn ($email) => $identities->normalizeAndValidate($email))->unique()->values();
        $id = (string) Str::uuid();
        $relativePath = "documents/$id/current.pdf";
        $absolutePath = Storage::disk('local')->path($relativePath);

        try {
            $pages = $pdf->sanitize($request->file('document')->getRealPath(), $absolutePath);
            $document = DB::transaction(function () use ($data, $request, $signers, $id, $relativePath, $absolutePath, $pages) {
                $document = Document::create([
                    'id' => $id,
                    'verification_code' => Str::random(48),
                    'requester_id' => $request->user()->id,
                    'title' => $data['title'],
                    'document_type' => $data['document_type'] ?? null,
                    'status' => 'pending',
                    'original_name' => $request->file('document')->getClientOriginalName(),
                    'file_path' => $relativePath,
                    'file_size' => filesize($absolutePath),
                    'page_count' => $pages,
                    'sha256' => hash_file('sha256', $absolutePath),
                    'expires_at' => $data['expires_at'] ?? now()->addDays(15),
                ]);
                foreach ($signers as $index => $email) {
                    $known = User::whereRaw('LOWER(email) = ?', [$email])->first();
                    SignatureRequest::create([
                        'document_id' => $document->id,
                        'signer_id' => $known?->id,
                        'signer_email' => $email,
                        'signer_name' => $known?->name ?? $email,
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

        $audit->record('document.created', $document, $request->user(), ['signers' => $signers->all(), 'sha256' => $document->sha256], $request);
        DispatchDocumentNotification::dispatch($document->id, 'document.requested');

        return redirect()->route('documents.edit', $document)->with('success', 'Document créé. Positionnez maintenant les zones de signature.');
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

        return view('documents.editor', compact('document'));
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

    public function export(Request $request): StreamedResponse
    {
        $documents = Document::with('signatureRequests')->where(function ($q) use ($request) {
            $q->where('requester_id', $request->user()->id)
                ->orWhereHas('signatureRequests', fn ($sq) => $sq->where('signer_email', mb_strtolower($request->user()->email)));
        })->latest()->get();

        return response()->streamDownload(function () use ($documents) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Référence', 'Titre', 'Statut', 'Demandeur', 'Signataires', 'Créé le', 'Signé le']);
            foreach ($documents as $document) {
                fputcsv($out, [
                    $document->id, $document->title, $document->status, $document->requester_id,
                    $document->signatureRequests->pluck('signer_email')->join('; '), $document->created_at, $document->completed_at,
                ]);
            }
            fclose($out);
        }, 'historique-signatures.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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
