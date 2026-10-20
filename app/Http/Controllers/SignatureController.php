<?php

namespace App\Http\Controllers;

use App\Jobs\DispatchDocumentNotification;
use App\Models\Document;
use App\Models\DocumentField;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Services\AuditService;
use App\Services\EidSigningService;
use App\Services\EncryptedSignatureStorage;
use App\Services\InternalIdentityService;
use App\Services\PdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SignatureController extends Controller
{
    public function show(Request $request, SignatureRequest $signatureRequest): View
    {
        $this->authorizeSigner($request, $signatureRequest);
        $signatureRequest->load(['document.requester', 'fields']);
        $batch = $signatureRequest->document->batch_id
            ? $signatureRequest->document->batchSiblings()->get(['id', 'batch_id', 'created_at', 'title'])
            : collect();

        return view('signatures.show', [
            'signatureRequest' => $signatureRequest,
            'knownUsers' => User::knownForSigning(),
            'batchPosition' => $batch->isEmpty() ? null : [$batch->search(fn (Document $sibling) => $sibling->id === $signatureRequest->document_id) + 1, $batch->count()],
            'eidBlocked' => $this->eidBlockedByOtherFields($signatureRequest),
            'hasEidSignature' => $signatureRequest->document->signatureRequests()->whereNotNull('certificate_info')->exists(),
        ]);
    }

    public function sign(Request $request, SignatureRequest $signatureRequest, EncryptedSignatureStorage $images, PdfService $pdf, AuditService $audit): RedirectResponse
    {
        $this->authorizeSigner($request, $signatureRequest);
        abort_if($signatureRequest->document->usesEid(), 409, 'Ce document se signe avec la carte eID.');
        abort_unless($request->user()->signature_path, 422, 'Ajoutez d’abord votre image de signature dans votre profil.');
        $this->validateSignatureOptions($request);
        $values = $this->validatedFieldValues($request, $signatureRequest);

        $source = Storage::disk('local')->path($signatureRequest->document->file_path);
        $backup = tempnam(sys_get_temp_dir(), 'visa-pdf-backup-');
        throw_unless(copy($source, $backup), new \RuntimeException('Impossible de sécuriser la version courante du PDF.'));

        try {
            DB::transaction(function () use ($request, $signatureRequest, $images, $pdf, $audit, $values) {
                $signatureRequest = SignatureRequest::lockForUpdate()->findOrFail($signatureRequest->id);
                $document = Document::lockForUpdate()->findOrFail($signatureRequest->document_id);
                abort_unless($signatureRequest->status === 'pending' && $document->status === 'pending', 409, 'Cette demande n’est plus signable.');
                abort_if($document->expires_at?->isPast(), 409, 'Cette demande a expiré.');

                $isLast = ! $document->signatureRequests()->where('status', 'pending')->whereKeyNot($signatureRequest->id)->exists();
                if ($isLast) {
                    abort_unless(is_file(config('services.signature.certificate')) && is_file(config('services.signature.private_key')), 503, 'Le certificat de scellement n’est pas configuré.');
                }

                foreach ($signatureRequest->fields as $field) {
                    $field->update(['value' => $values[$field->id] ?? null, 'filled_at' => now()]);
                }
                $this->storeSignatureOptions($request, $signatureRequest);
                $sha = $pdf->stamp($document, $signatureRequest, $images->bytes($request->user()));
                $signatureRequest->update(['status' => 'signed', 'signed_at' => now(), 'signer_id' => $request->user()->id, 'document_sha256' => $sha]);
                $document->update(['sha256' => $sha, 'file_size' => filesize(Storage::disk('local')->path($document->file_path)), 'lock_version' => $document->lock_version + 1]);
                $audit->record('signature.signed', $document, $request->user(), ['signature_request_id' => $signatureRequest->id, 'sha256' => $sha, 'consent' => true], $request);

                if ($isLast) {
                    $sealed = $pdf->seal($document);
                    $document->update(['status' => 'signed', 'completed_at' => now(), 'sha256' => $sealed, 'sealed_sha256' => $sealed]);
                    $audit->record('document.completed', $document, $request->user(), ['sealed_sha256' => $sealed], $request);
                }
            }, 3);
        } catch (\Throwable $exception) {
            copy($backup, $source);
            throw $exception;
        } finally {
            @unlink($backup);
        }

        DispatchDocumentNotification::dispatch($signatureRequest->document_id, 'signature.signed');

        return redirect($this->nextUrl($request, $signatureRequest))->with('success', 'Signature apposée et horodatée.');
    }

    /**
     * Étape 1 de la signature eID : certificat de la carte → empreinte à faire signer.
     */
    public function eidPrepare(Request $request, SignatureRequest $signatureRequest, EidSigningService $eid): JsonResponse
    {
        $this->authorizeSigner($request, $signatureRequest);
        abort_if($this->eidBlockedByOtherFields($signatureRequest), 409, 'Signature eID indisponible : d’autres signataires doivent encore compléter des champs.');
        $data = $request->validate([
            'certificate' => ['required', 'string', 'max:20000'],
            'supportedSignatureAlgorithms' => ['required', 'array', 'max:50'],
            'supportedSignatureAlgorithms.*.cryptoAlgorithm' => ['required', 'string', 'max:16'],
            'supportedSignatureAlgorithms.*.hashFunction' => ['required', 'string', 'max:16'],
            'supportedSignatureAlgorithms.*.paddingScheme' => ['required', 'string', 'max:16'],
        ]);
        $this->validateSignatureOptions($request);
        $values = $this->validatedFieldValues($request, $signatureRequest);
        $this->storeSignatureOptions($request, $signatureRequest);

        return response()->json($eid->prepare($signatureRequest->fresh(['document', 'fields']), $request->user(), $data['certificate'], $data['supportedSignatureAlgorithms'], $values));
    }

    /**
     * Étape 2 de la signature eID : valeur produite par la carte → PDF signé.
     */
    public function eidFinalize(Request $request, SignatureRequest $signatureRequest, EidSigningService $eid): JsonResponse
    {
        $this->authorizeSigner($request, $signatureRequest);
        $data = $request->validate([
            'signature' => ['required', 'string', 'max:4000'],
            'signatureAlgorithm' => ['required', 'array'],
            'signatureAlgorithm.cryptoAlgorithm' => ['required', 'string', 'max:16'],
            'signatureAlgorithm.hashFunction' => ['required', 'string', 'max:16'],
            'signatureAlgorithm.paddingScheme' => ['nullable', 'string', 'max:16'],
        ]);
        $eid->finalize($signatureRequest, $request->user(), $data['signature'], $data['signatureAlgorithm']);
        DispatchDocumentNotification::dispatch($signatureRequest->document_id, 'signature.signed');
        session()->flash('success', 'Signature eID apposée.');

        return response()->json(['redirect' => $this->nextUrl($request, $signatureRequest)]);
    }

    public function position(Request $request, SignatureRequest $signatureRequest, AuditService $audit): JsonResponse
    {
        $this->authorizeSigner($request, $signatureRequest);
        abort_unless($signatureRequest->document->signer_can_move, 403, 'Le demandeur a verrouillé l’emplacement de la signature.');
        $data = $request->validate([
            'page' => ['required', 'integer', 'min:1', 'max:'.$signatureRequest->document->page_count],
            'x' => ['required', 'numeric', 'between:0,0.95'], 'y' => ['required', 'numeric', 'between:0,0.95'],
            'width' => ['required', 'numeric', 'between:0.08,0.8'], 'height' => ['required', 'numeric', 'between:0.04,0.4'],
        ]);
        $signatureRequest->update(['page' => $data['page'], 'position_x' => $data['x'], 'position_y' => $data['y'], 'width' => $data['width'], 'height' => $data['height']]);
        $audit->record('signature.position.updated', $signatureRequest->document, $request->user(), ['signature_request_id' => $signatureRequest->id], $request);

        return response()->json(['message' => 'Position enregistrée.']);
    }

    public function reject(Request $request, SignatureRequest $signatureRequest, AuditService $audit): RedirectResponse
    {
        $this->authorizeSigner($request, $signatureRequest);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        DB::transaction(function () use ($request, $signatureRequest, $data, $audit) {
            $signatureRequest->update(['status' => 'rejected', 'rejected_at' => now(), 'rejection_reason' => $data['reason']]);
            $signatureRequest->document()->update(['status' => 'rejected']);
            $audit->record('signature.rejected', $signatureRequest->document, $request->user(), ['reason' => $data['reason']], $request);
        });
        DispatchDocumentNotification::dispatch($signatureRequest->document_id, 'signature.rejected');

        return redirect()->route('dashboard')->with('success', 'Le refus a été transmis au demandeur.');
    }

    public function delegate(Request $request, SignatureRequest $signatureRequest, InternalIdentityService $identities, AuditService $audit): RedirectResponse
    {
        $this->authorizeSigner($request, $signatureRequest);
        $data = $request->validate(['email' => ['required', 'email:rfc']]);
        $email = $identities->normalizeAndValidate($data['email']);
        abort_if($email === mb_strtolower($request->user()->email), 422, 'Choisissez un autre utilisateur.');
        $known = User::whereRaw('LOWER(email) = ?', [$email])->first();
        DB::transaction(function () use ($request, $signatureRequest, $email, $known, $audit) {
            $signatureRequest->update(['status' => 'delegated']);
            $next = SignatureRequest::create([
                'document_id' => $signatureRequest->document_id, 'signer_id' => $known?->id,
                'delegated_from_id' => $signatureRequest->id, 'signer_email' => $email,
                'signer_name' => $known?->name ?? $email, 'status' => 'pending',
                'page' => $signatureRequest->page, 'position_x' => $signatureRequest->position_x,
                'position_y' => $signatureRequest->position_y, 'width' => $signatureRequest->width,
                'height' => $signatureRequest->height, 'display_options' => $signatureRequest->display_options,
            ]);
            $signatureRequest->fields()->where('added_by_signer', false)->update(['signature_request_id' => $next->id]);
            $signatureRequest->fields()->delete();
            $audit->record('signature.delegated', $signatureRequest->document, $request->user(), ['from' => $signatureRequest->signer_email, 'to' => $email, 'signature_request_id' => $next->id], $request);
        });
        DispatchDocumentNotification::dispatch($signatureRequest->document_id, 'signature.delegated');

        return redirect()->route('dashboard')->with('success', 'La demande a été déléguée.');
    }

    public function storeField(Request $request, SignatureRequest $signatureRequest, AuditService $audit): JsonResponse
    {
        $this->authorizeSigner($request, $signatureRequest);
        abort_if($signatureRequest->document->usesEid(), 422, 'Les champs à compléter ne sont pas disponibles avec la signature eID.');
        $data = $request->validate([
            'type' => ['required', Rule::in(DocumentField::TYPES)],
            ...DocumentField::geometryRules($signatureRequest->document->page_count),
        ]);
        abort_if($signatureRequest->fields()->where('added_by_signer', true)->count() >= 30, 422, 'Nombre maximal de champs atteint.');
        $field = $signatureRequest->fields()->create([
            'document_id' => $signatureRequest->document_id, 'type' => $data['type'], 'added_by_signer' => true,
            'page' => $data['page'], 'position_x' => $data['x'], 'position_y' => $data['y'],
            'width' => $data['width'], 'height' => $data['height'],
        ]);
        $audit->record('signature.field.created', $signatureRequest->document, $request->user(), ['field_id' => $field->id, 'type' => $field->type], $request);

        return response()->json($field->load('signatureRequest')->toEditorArray(), 201);
    }

    public function updateField(Request $request, SignatureRequest $signatureRequest, DocumentField $field): JsonResponse
    {
        $this->authorizeSigner($request, $signatureRequest);
        abort_unless($field->signature_request_id === $signatureRequest->id && $field->added_by_signer, 404);
        $data = $request->validate(DocumentField::geometryRules($signatureRequest->document->page_count));
        $field->update(['page' => $data['page'], 'position_x' => $data['x'], 'position_y' => $data['y'], 'width' => $data['width'], 'height' => $data['height']]);

        return response()->json(['message' => 'Champ enregistré.']);
    }

    public function destroyField(Request $request, SignatureRequest $signatureRequest, DocumentField $field): Response
    {
        $this->authorizeSigner($request, $signatureRequest);
        abort_unless($field->signature_request_id === $signatureRequest->id && $field->added_by_signer, 404);
        $field->delete();

        return response()->noContent();
    }

    /**
     * Valide les valeurs saisies pour les champs du signataire et les normalise (date ISO, coche « 1 »).
     *
     * @return array<string, ?string>
     *
     * @throws ValidationException
     */
    private function validatedFieldValues(Request $request, SignatureRequest $signatureRequest): array
    {
        $fields = $signatureRequest->fields()->get();
        $rules = [];
        foreach ($fields as $field) {
            $presence = $field->required ? ($field->type === 'checkbox' ? 'accepted' : 'required') : 'nullable';
            $rules["fields.{$field->id}"] = match ($field->type) {
                'date' => [$presence, 'date'],
                'checkbox' => [$presence, 'boolean'],
                default => [$presence, 'string', 'max:200'],
            };
        }
        $input = $request->validate($rules, [
            'fields.*.required' => 'Ce champ obligatoire doit être complété.',
            'fields.*.accepted' => 'Cette case obligatoire doit être cochée.',
        ])['fields'] ?? [];

        return $fields->mapWithKeys(fn (DocumentField $field) => [$field->id => match ($field->type) {
            'checkbox' => filter_var($input[$field->id] ?? false, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'date' => filled($input[$field->id] ?? null) ? date('Y-m-d', strtotime($input[$field->id])) : null,
            default => filled($input[$field->id] ?? null) ? trim($input[$field->id]) : null,
        }])->all();
    }

    /**
     * Journalise un échec de Web eID côté poste (étape, code d'erreur) pour le diagnostic.
     */
    public function eidFailure(Request $request, SignatureRequest $signatureRequest): JsonResponse
    {
        $this->authorizeSigner($request, $signatureRequest);
        $data = $request->validate([
            'step' => ['required', 'string', 'in:status,certificate,prepare,sign,finalize'],
            'code' => ['nullable', 'string', 'max:80'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);
        Log::warning('eID : échec côté poste', [...$data, 'signature_request_id' => $signatureRequest->id, 'user_agent' => Str::limit((string) $request->userAgent(), 200)]);

        return response()->json(['logged' => true]);
    }

    /**
     * Une signature eID fige le PDF : impossible tant que d'autres signataires ont des champs à compléter.
     */
    private function eidBlockedByOtherFields(SignatureRequest $signatureRequest): bool
    {
        return DocumentField::query()
            ->where('document_id', $signatureRequest->document_id)
            ->where('signature_request_id', '!=', $signatureRequest->id)
            ->whereHas('signatureRequest', fn ($query) => $query->where('status', 'pending'))
            ->exists();
    }

    /**
     * Mentions choisies (nom, qualité, date) ; la qualité saisie est gardée dans le profil.
     */
    private function validateSignatureOptions(Request $request): void
    {
        $options = $request->validate([
            'consent' => ['accepted'],
            'show_name' => ['nullable', 'boolean'],
            'show_job_title' => ['nullable', 'boolean'],
            'show_date' => ['nullable', 'boolean'],
            'job_title' => ['nullable', 'string', 'max:120', 'required_if_accepted:show_job_title'],
        ], ['consent.accepted' => 'Cochez la confirmation de lecture pour signer.']);
        if (filled($options['job_title'] ?? null) && $options['job_title'] !== $request->user()->job_title) {
            $request->user()->update(['job_title' => $options['job_title']]);
        }
    }

    private function storeSignatureOptions(Request $request, SignatureRequest $signatureRequest): void
    {
        $signatureRequest->update([
            'signer_name' => $request->user()->signatureName(),
            'signer_job_title' => $request->user()->job_title,
            'display_options' => [
                ...($signatureRequest->display_options ?? []),
                'show_name' => $request->boolean('show_name'),
                'show_job_title' => $request->boolean('show_job_title'),
                'show_date' => $request->boolean('show_date'),
            ],
        ]);
    }

    /**
     * Après signature : document suivant du même lot à signer, sinon la fiche du document.
     */
    private function nextUrl(Request $request, SignatureRequest $signatureRequest): string
    {
        $document = Document::find($signatureRequest->document_id);
        $next = $document?->batch_id ? SignatureRequest::query()
            ->where('signer_email', mb_strtolower($request->user()->email))
            ->where('status', 'pending')
            ->whereHas('document', fn ($query) => $query->where('batch_id', $document->batch_id)->where('status', 'pending'))
            ->first() : null;

        return $next ? route('signatures.show', $next) : route('documents.show', $signatureRequest->document_id);
    }

    private function authorizeSigner(Request $request, SignatureRequest $signatureRequest): void
    {
        abort_unless($signatureRequest->status === 'pending' && mb_strtolower($signatureRequest->signer_email) === mb_strtolower($request->user()->email), 403);
    }
}
