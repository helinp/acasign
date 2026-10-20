@extends('layouts.app')
@section('title', 'Nouvelle demande')
@section('content')
@php
    // Attributs d'accessibilité d'un champ en erreur (message relié via aria-describedby).
    $described = fn (string $key, string $id) => $errors->has($key) ? 'aria-describedby='.$id.'-error aria-invalid=true' : '';
@endphp
<a class="back" href="{{ route('dashboard') }}">← Accueil</a>
<h1 class="page-title">Nouvelle demande</h1>
<div class="steps" aria-label="Étapes"><span class="is-on" aria-current="step">1 · Document</span><span>2 · Placement</span></div>
<form method="post" action="{{ route('documents.store') }}" enctype="multipart/form-data" class="panel" style="max-width:760px" novalidate>@csrf
    <div class="mb-3">
        <label class="form-label" for="title">Titre</label>
        <input class="form-control @error('title') is-invalid @enderror" id="title" name="title" value="{{ old('title') }}" required maxlength="180" {{ $described('title', 'title') }}>
        <x-field-error name="title" />
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label" for="document_type">Type</label>
            <select class="form-select @error('document_type') is-invalid @enderror" id="document_type" name="document_type" required {{ $described('document_type', 'document_type') }}><option value="">— Choisir —</option>@foreach($documentTypes as $type)<option value="{{ $type->name }}" @selected(old('document_type') === $type->name)>{{ $type->name }}{{ $type->requires_eid ? ' · carte eID' : '' }}</option>@endforeach</select>
            <x-field-error name="document_type" />
        </div>
        <div class="col-md-6">
            <label class="form-label" for="expires_at">À signer avant le</label>
            <input class="form-control @error('expires_at') is-invalid @enderror" type="date" id="expires_at" name="expires_at" value="{{ old('expires_at', now()->addDays(15)->format('Y-m-d')) }}" {{ $described('expires_at', 'expires_at') }}>
            <x-field-error name="expires_at" />
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label" for="documents">PDF <span class="text-muted small">un ou plusieurs · 25 Mo et 200 pages max. chacun</span></label>
        <input type="file" class="form-control @if($errors->has('documents') || $errors->has('documents.*') || $errors->has('document')) is-invalid @endif" id="documents" name="documents[]" accept="application/pdf" multiple required data-file-list aria-describedby="documents-help">
        <div class="small text-muted mt-1" id="documents-help">Plusieurs fichiers : un document par PDF, avec les mêmes signataires.</div>
        <ul class="file-list" data-file-list-output aria-live="polite"></ul>
        <x-field-error name="documents" />
        <x-field-error name="document" id="documents" />
        @foreach($errors->get('documents.*') as $messages)<div class="invalid-feedback d-block">{{ $messages[0] }}</div>@endforeach
    </div>
    <fieldset class="mb-4">
        <div class="d-flex justify-content-between align-items-center mb-2"><legend class="form-label mb-0 w-auto">Signataires</legend><button class="btn btn-sm btn-outline-primary" type="button" data-add-signer>+ Ajouter</button></div>
        <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="self_sign" value="1" data-self-sign @checked(old('self_sign'))> <span>Je signe moi-même <span class="text-muted small">({{ auth()->user()->email }})</span></span></label>
        <div data-signer-list id="signers">
            @foreach(old('signers',['']) as $index => $email)<div class="input-group mb-2"><input class="form-control @error('signers.'.$index) is-invalid @enderror" type="email" id="signers-{{ $index }}" name="signers[]" value="{{ $email }}" placeholder="prenom.nom@acarts.be" list="known-users" autocomplete="off" aria-label="Adresse d’un autre signataire"><button class="btn btn-outline-danger" type="button" data-remove-signer aria-label="Retirer ce signataire">×</button></div><x-field-error :name="'signers.'.$index" />@endforeach
        </div>
        <x-field-error name="signers" />
        @include('partials.known-users-datalist')
    </fieldset>
    <button class="btn btn-primary">Continuer vers le placement →</button>
</form>
@endsection
