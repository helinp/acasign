@extends('layouts.app')
@section('title', 'Nouvelle demande')
@section('content')
<a class="back" href="{{ route('dashboard') }}">← Accueil</a>
<h1 class="page-title">Nouvelle demande</h1>
<div class="steps" aria-label="Étapes"><span class="is-on">1 · Document</span><span>2 · Placement</span></div>
<form method="post" action="{{ route('documents.store') }}" enctype="multipart/form-data" class="panel" style="max-width:760px">@csrf
    <div class="mb-3"><label class="form-label" for="title">Titre</label><input class="form-control" id="title" name="title" value="{{ old('title') }}" required maxlength="180"></div>
    <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label" for="document_type">Type</label><select class="form-select @error('document_type') is-invalid @enderror" id="document_type" name="document_type" required><option value="">— Choisir —</option>@foreach($documentTypes as $type)<option value="{{ $type->name }}" @selected(old('document_type') === $type->name)>{{ $type->name }}{{ $type->requires_eid ? ' · carte eID' : '' }}</option>@endforeach</select></div>
        <div class="col-md-6"><label class="form-label" for="expires_at">À signer avant le</label><input class="form-control" type="date" id="expires_at" name="expires_at" value="{{ old('expires_at', now()->addDays(15)->format('Y-m-d')) }}"></div>
    </div>
    <div class="mb-3"><label class="form-label" for="documents">PDF <span class="text-muted small">un ou plusieurs · 25 Mo et 200 pages max. chacun</span></label><input type="file" class="form-control" id="documents" name="documents[]" accept="application/pdf" multiple required><div class="small text-muted mt-1">Plusieurs fichiers : un document par PDF, avec les mêmes signataires.</div></div>
    <div class="mb-4">
        <div class="d-flex justify-content-between align-items-center mb-2"><span class="form-label mb-0">Signataires</span><button class="btn btn-sm btn-outline-primary" type="button" data-add-signer>+ Ajouter</button></div>
        <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="self_sign" value="1" data-self-sign @checked(old('self_sign'))> <span>Je signe moi-même <span class="text-muted small">({{ auth()->user()->email }})</span></span></label>
        <div data-signer-list>
            @foreach(old('signers',['']) as $email)<div class="input-group mb-2"><input class="form-control" type="email" name="signers[]" value="{{ $email }}" placeholder="prenom.nom@acarts.be" list="known-users" autocomplete="off" aria-label="Adresse d’un autre signataire"><button class="btn btn-outline-danger" type="button" data-remove-signer aria-label="Retirer">×</button></div>@endforeach
        </div>
        @include('partials.known-users-datalist')
    </div>
    <button class="btn btn-primary">Continuer vers le placement →</button>
</form>
@endsection
