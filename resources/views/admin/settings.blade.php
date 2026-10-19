@extends('layouts.app')
@section('title', 'Réglages')
@section('content')
<h1 class="page-title">Administration</h1>
@include('admin.partials.tabs')
<form method="post" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data" class="panel" style="max-width:640px">@csrf @method('PUT')
    <div class="mb-4">
        <label class="form-label" for="entity_name">Nom de l’entité</label>
        <input class="form-control @error('entity_name') is-invalid @enderror" id="entity_name" name="entity_name" maxlength="120" value="{{ old('entity_name', $entityName) }}" placeholder="{{ config('app.name') }}">
    </div>
    <div class="mb-4">
        <label class="form-label" for="logo">Logo <span class="text-muted small">PNG, JPEG ou WebP · 1 Mo</span></label>
        @if($logoPath)<div class="d-flex align-items-center gap-3 mb-2"><img src="{{ route('branding.logo', ['v' => substr(md5($logoPath), 0, 8)]) }}" alt="Logo actuel" style="height:48px;width:auto"><label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="remove_logo" value="1"> <span class="small">Retirer</span></label></div>@endif
        <input class="form-control @error('logo') is-invalid @enderror" type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp">
    </div>
    <button class="btn btn-primary">Enregistrer</button>
</form>
@endsection
