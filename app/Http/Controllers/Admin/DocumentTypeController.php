<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentType;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DocumentTypeController extends Controller
{
    public function index(): View
    {
        return view('admin.document-types', [
            'types' => DocumentType::orderBy('name')->get(),
            'usage' => Document::query()->whereNotNull('document_type')->groupBy('document_type')
                ->selectRaw('document_type, count(*) as total')->pluck('total', 'document_type'),
        ]);
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80', Rule::unique('document_types', 'name')]]);
        $type = DocumentType::create(['name' => trim($data['name']), 'is_active' => true]);
        $audit->record('admin.document_type.created', null, $request->user(), ['document_type_id' => $type->id, 'name' => $type->name], $request);

        return back()->with('success', 'Type de document ajouté.');
    }

    public function update(Request $request, DocumentType $documentType, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('document_types', 'name')->ignore($documentType)],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $previousName = $documentType->name;
        DB::transaction(function () use ($documentType, $data, $previousName) {
            $documentType->update(['name' => trim($data['name']), 'is_active' => (bool) ($data['is_active'] ?? false)]);
            if ($documentType->name !== $previousName) {
                Document::where('document_type', $previousName)->update(['document_type' => $documentType->name]);
            }
        });
        $audit->record('admin.document_type.updated', null, $request->user(), ['document_type_id' => $documentType->id, 'name' => $documentType->name, 'is_active' => $documentType->is_active], $request);

        return back()->with('success', 'Type de document mis à jour.');
    }

    public function destroy(Request $request, DocumentType $documentType, AuditService $audit): RedirectResponse
    {
        if (Document::where('document_type', $documentType->name)->exists()) {
            return back()->withErrors(['type' => "« {$documentType->name} » est utilisé par des documents : désactivez-le plutôt."]);
        }
        $documentType->delete();
        $audit->record('admin.document_type.deleted', null, $request->user(), ['name' => $documentType->name], $request);

        return back()->with('success', 'Type de document supprimé.');
    }
}
