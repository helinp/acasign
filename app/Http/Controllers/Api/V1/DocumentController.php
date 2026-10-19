<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchDocumentNotification;
use App\Models\Document;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Services\AuditService;
use App\Services\InternalIdentityService;
use App\Services\PdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function store(Request $request, PdfService $pdf, InternalIdentityService $identities, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:25600'],
            'title' => ['required', 'string', 'max:180'],
            'external_reference' => ['required', 'string', 'max:120'],
            'requester_email' => ['required', 'email:rfc'],
            'signers' => ['required', 'array', 'min:1', 'max:20'],
            'signers.*.email' => ['required', 'email:rfc', 'distinct'],
            'signers.*.page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'signers.*.x' => ['nullable', 'numeric', 'between:0,0.95'],
            'signers.*.y' => ['nullable', 'numeric', 'between:0,0.95'],
            'document_type' => ['nullable', 'string', 'max:80'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);
        if ($existing = Document::where('external_reference', $data['external_reference'])->first()) {
            return response()->json($this->resource($existing), 200);
        }
        $requesterEmail = $identities->normalizeAndValidate($data['requester_email']);
        $requester = User::whereRaw('LOWER(email) = ?', [$requesterEmail])->firstOrFail();
        $signers = collect($data['signers'])->map(function ($signer) use ($identities) {
            $signer['email'] = $identities->normalizeAndValidate($signer['email']);

            return $signer;
        });
        $id = (string) Str::uuid();
        $path = "documents/$id/current.pdf";
        $absolute = Storage::disk('local')->path($path);
        try {
            $pages = $pdf->sanitize($request->file('file')->getRealPath(), $absolute);
            $document = DB::transaction(function () use ($data, $request, $requester, $signers, $id, $path, $absolute, $pages) {
                $document = Document::create([
                    'id' => $id, 'verification_code' => Str::random(48), 'requester_id' => $requester->id,
                    'title' => $data['title'], 'external_reference' => $data['external_reference'],
                    'document_type' => $data['document_type'] ?? null, 'status' => 'pending',
                    'original_name' => $request->file('file')->getClientOriginalName(), 'file_path' => $path,
                    'file_size' => filesize($absolute), 'page_count' => $pages, 'sha256' => hash_file('sha256', $absolute),
                    'expires_at' => now()->addDays($data['expires_in_days'] ?? 15),
                ]);
                foreach ($signers as $index => $signer) {
                    $known = User::whereRaw('LOWER(email) = ?', [$signer['email']])->first();
                    SignatureRequest::create([
                        'document_id' => $document->id, 'signer_id' => $known?->id,
                        'signer_email' => $signer['email'], 'signer_name' => $known?->name ?? $signer['email'],
                        'page' => min($pages, $signer['page'] ?? $pages), 'position_x' => $signer['x'] ?? .62,
                        'position_y' => $signer['y'] ?? max(.05, .80 - ($index * .14)), 'width' => .28, 'height' => .12,
                        'display_options' => ['show_name' => true, 'show_date' => true],
                    ]);
                }

                return $document;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        $audit->record('api.document.created', $document, null, ['client' => $request->attributes->get('api_client'), 'external_reference' => $data['external_reference']]);
        DispatchDocumentNotification::dispatch($document->id, 'document.requested');

        return response()->json($this->resource($document), 201);
    }

    public function show(Document $document): JsonResponse
    {
        return response()->json($this->resource($document));
    }

    public function cancel(Document $document, AuditService $audit): JsonResponse
    {
        abort_if($document->isTerminal(), 409, 'Document déjà terminé.');
        $document->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $audit->record('api.document.cancelled', $document, null);

        return response()->json($this->resource($document));
    }

    public function revoke(Request $request, Document $document, AuditService $audit): JsonResponse
    {
        abort_unless($document->status === 'signed', 409, 'Seul un document signé peut être révoqué.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $document->update(['status' => 'revoked', 'revoked_at' => now(), 'revocation_reason' => $data['reason']]);
        $audit->record('api.document.revoked', $document, null, ['reason' => $data['reason']]);

        return response()->json($this->resource($document));
    }

    public function download(Document $document): StreamedResponse
    {
        abort_unless(in_array($document->status, ['signed', 'revoked'], true), 409, 'Le PDF final n’est pas encore disponible.');

        return Storage::disk('local')->download($document->file_path, $document->original_name);
    }

    private function resource(Document $document): array
    {
        $document->loadMissing('signatureRequests');

        return [
            'id' => $document->id, 'external_reference' => $document->external_reference,
            'title' => $document->title, 'status' => $document->status,
            'expires_at' => $document->expires_at?->toIso8601String(), 'completed_at' => $document->completed_at?->toIso8601String(),
            'sha256' => $document->sealed_sha256 ?? $document->sha256,
            'signers' => $document->signatureRequests->map(fn ($s) => ['email' => $s->signer_email, 'status' => $s->status, 'signed_at' => $s->signed_at?->toIso8601String()]),
        ];
    }
}
