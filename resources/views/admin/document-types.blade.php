@extends('layouts.app')
@section('title', 'Types de documents')
@section('content')
<h1 class="page-title">Administration</h1>
@include('admin.partials.tabs')
<div class="panel mb-4">
    <form method="post" action="{{ route('admin.document-types.store') }}" class="d-flex flex-wrap gap-2 align-items-start">@csrf
        <div class="flex-grow-1"><input class="form-control @error('name') is-invalid @enderror" name="name" maxlength="80" value="{{ old('name') }}" placeholder="Nouveau type" aria-label="Nouveau type" required>@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <label class="form-check align-self-center mb-0"><input class="form-check-input" type="checkbox" name="requires_eid" value="1"> <span class="small">Signature eID requise</span></label>
        <button class="btn btn-primary"><x-icon name="plus" />Ajouter</button>
    </form>
</div>
<div class="band"><span class="band__title"><x-icon name="tags" />Types de documents</span> <span>{{ $types->where('is_active', true)->count() }} actifs</span></div>
<div class="card border-top-0"><div class="table-responsive"><table class="table card-table align-middle"><thead><tr><th>Type</th><th>Documents</th><th>Proposé</th><th>Signature eID</th><th></th></tr></thead><tbody>
@foreach($types as $type)
    @php($formId = 'type-'.$type->id)
    <tr>
        <td><form id="{{ $formId }}" method="post" action="{{ route('admin.document-types.update', $type) }}">@csrf @method('PUT')</form><input class="form-control form-control-sm" name="name" maxlength="80" value="{{ $type->name }}" form="{{ $formId }}" required></td>
        <td>{{ $usage[$type->name] ?? 0 }}</td>
        <td><label class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" form="{{ $formId }}" @checked($type->is_active)> <span class="small">Actif</span></label></td>
        <td><label class="form-check"><input class="form-check-input" type="checkbox" name="requires_eid" value="1" form="{{ $formId }}" @checked($type->requires_eid)> <span class="small">Requise</span></label></td>
        <td class="text-end text-nowrap">
            <button class="btn btn-sm btn-outline-primary" form="{{ $formId }}"><x-icon name="device-floppy" />Enregistrer</button>
            @if((int) ($usage[$type->name] ?? 0) === 0)
                <form method="post" action="{{ route('admin.document-types.destroy', $type) }}" class="d-inline" data-confirm="Supprimer « {{ $type->name }} » ?">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" aria-label="Supprimer {{ $type->name }}"><x-icon name="trash" />Supprimer</button></form>
            @endif
        </td>
    </tr>
@endforeach
</tbody></table></div></div>
<p class="small text-muted mt-2">Un type utilisé ne peut pas être supprimé : désactivez-le. « Signature eID requise » s’applique aux nouveaux documents.</p>
@endsection
