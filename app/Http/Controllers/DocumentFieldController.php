<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentField;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Champs à compléter (date, coche, texte) placés par le demandeur et attribués à un signataire.
 */
class DocumentFieldController extends Controller
{
    public function store(Request $request, Document $document, AuditService $audit): JsonResponse
    {
        $this->authorizeRequester($request, $document);
        abort_if($document->usesEid(), 422, 'Les champs à compléter ne sont pas disponibles avec la signature eID.');
        $data = $request->validate([
            'type' => ['required', Rule::in(DocumentField::TYPES)],
            'signature_request_id' => ['required', 'uuid', Rule::exists('signature_requests', 'id')->where('document_id', $document->id)->where('status', 'pending')],
            'label' => ['nullable', 'string', 'max:80'],
            'required' => ['nullable', 'boolean'],
            ...DocumentField::geometryRules($document->page_count),
        ]);
        $field = $document->fields()->create([
            'signature_request_id' => $data['signature_request_id'], 'type' => $data['type'],
            'label' => $data['label'] ?? null, 'required' => (bool) ($data['required'] ?? false),
            'page' => $data['page'], 'position_x' => $data['x'], 'position_y' => $data['y'],
            'width' => $data['width'], 'height' => $data['height'],
        ]);
        $audit->record('document.field.created', $document, $request->user(), ['field_id' => $field->id, 'type' => $field->type], $request);

        return response()->json($field->load('signatureRequest')->toEditorArray(), 201);
    }

    public function update(Request $request, Document $document, DocumentField $field): JsonResponse
    {
        $this->authorizeRequester($request, $document);
        abort_unless($field->document_id === $document->id && ! $field->added_by_signer, 404);
        $data = $request->validate(DocumentField::geometryRules($document->page_count));
        $field->update(['page' => $data['page'], 'position_x' => $data['x'], 'position_y' => $data['y'], 'width' => $data['width'], 'height' => $data['height']]);

        return response()->json(['message' => 'Champ enregistré.']);
    }

    public function destroy(Request $request, Document $document, DocumentField $field, AuditService $audit): Response
    {
        $this->authorizeRequester($request, $document);
        abort_unless($field->document_id === $document->id && ! $field->added_by_signer, 404);
        $field->delete();
        $audit->record('document.field.deleted', $document, $request->user(), ['field_id' => $field->id], $request);

        return response()->noContent();
    }

    private function authorizeRequester(Request $request, Document $document): void
    {
        abort_unless($document->requester_id === $request->user()->id && $document->status === 'pending', 403);
    }
}
