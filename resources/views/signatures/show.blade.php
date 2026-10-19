@extends('layouts.app')
@section('title', 'Signer '.$signatureRequest->document->title)
@section('content')
@php
    $s = $signatureRequest;
    $d = $s->document;
    $me = auth()->user();
    $signOptions = $s->display_options ?? [];
    $placements = collect([['id' => $s->id, 'label' => $me->signatureName(), 'page' => $s->page, 'x' => $s->position_x, 'y' => $s->position_y, 'width' => $s->width, 'height' => $s->height]]);
@endphp
<a class="back" href="{{ route('dashboard') }}">← Accueil</a>
<div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
    <div><p class="eyebrow">{{ $d->document_type ?: 'Signature' }} · {{ $d->requester->name }} · avant le {{ $d->expires_at->format('d/m/Y') }}</p><h1 class="page-title mb-0">{{ $d->title }}</h1></div>
    <span class="small text-muted" data-save-state></span>
</div>
@unless($me->signature_path)<div class="alert alert-warning">Aucune image de signature enregistrée. <a href="{{ route('profile.show') }}"><strong>L’ajouter</strong></a></div>@endunless
<div class="d-flex flex-wrap align-items-center gap-2 mb-2"><span class="small text-muted">@if($s->fields->where('added_by_signer', false)->isNotEmpty())Complétez les cadres bleus, puis signez. @endif Ajouter un champ :</span><button type="button" class="btn btn-sm btn-outline-primary" data-add-field="date">Date</button><button type="button" class="btn btn-sm btn-outline-primary" data-add-field="checkbox">Coche</button><button type="button" class="btn btn-sm btn-outline-primary" data-add-field="text">Texte</button></div>
<div class="pdf-stage mb-3" data-pdf-stage data-single="true" data-pdf-url="{{ route('documents.file',$d) }}" data-save-url="{{ route('signatures.position',$s) }}" data-placements='@json($placements)' data-field-mode="fill" data-field-form="sign-form" data-fields='@json($s->fields->map->toEditorArray())' data-field-create-url="{{ route('signatures.fields.store', $s) }}" data-field-url-template="{{ route('signatures.fields.update', [$s, '__ID__']) }}"></div>
<div class="d-flex flex-wrap justify-content-end gap-2">
    <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#delegateModal">Déléguer</button>
    <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">Refuser</button>
    <button class="btn btn-coral btn-lg" data-bs-toggle="modal" data-bs-target="#signModal" @disabled(!$me->signature_path)>Signer</button>
</div>

<div class="modal fade" id="signModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" id="sign-form" method="post" action="{{ route('signatures.sign',$s) }}">@csrf
    <div class="band">Mentions sous la signature</div>
    <div class="modal-body">
        <div class="form-check mb-2"><input type="hidden" name="show_name" value="0"><input class="form-check-input" type="checkbox" name="show_name" value="1" id="show_name" @checked(old('show_name', $signOptions['show_name'] ?? true))><label class="form-check-label" for="show_name">{{ $me->signatureName() }}</label></div>
        <div class="form-check mb-1"><input type="hidden" name="show_job_title" value="0"><input class="form-check-input" type="checkbox" name="show_job_title" value="1" id="show_job_title" @checked(old('show_job_title', $signOptions['show_job_title'] ?? filled($me->job_title)))><label class="form-check-label" for="show_job_title">Qualité</label></div>
        <input class="form-control form-control-sm mb-2 @error('job_title') is-invalid @enderror" name="job_title" maxlength="120" value="{{ old('job_title', $me->job_title) }}" placeholder="Directeur, professeur…" aria-label="Qualité">
        <div class="form-check"><input type="hidden" name="show_date" value="0"><input class="form-check-input" type="checkbox" name="show_date" value="1" id="show_date" @checked(old('show_date', $signOptions['show_date'] ?? true))><label class="form-check-label" for="show_date">Date et heure</label></div>
    </div>
    <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Retour</button><button class="btn btn-coral">Signer</button></div>
</form></div></div>

<div class="modal fade" id="rejectModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post" action="{{ route('signatures.reject',$s) }}">@csrf
    <div class="band">Refuser</div>
    <div class="modal-body"><label class="form-label" for="reason">Motif</label><textarea class="form-control" id="reason" name="reason" required maxlength="1000"></textarea></div>
    <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Retour</button><button class="btn btn-danger">Refuser</button></div>
</form></div></div>

<div class="modal fade" id="delegateModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post" action="{{ route('signatures.delegate',$s) }}">@csrf
    <div class="band">Déléguer</div>
    <div class="modal-body"><label class="form-label" for="delegate-email">À</label><input class="form-control" id="delegate-email" type="email" name="email" required placeholder="prenom.nom@acarts.be" list="known-users" autocomplete="off">@include('partials.known-users-datalist')</div>
    <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Retour</button><button class="btn btn-primary">Déléguer</button></div>
</form></div></div>
@endsection
