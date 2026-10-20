@extends('layouts.app')
@section('title', 'Signer '.$signatureRequest->document->title)
@section('content')
@php
    $s = $signatureRequest;
    $d = $s->document;
    $me = auth()->user();
    // eidOnly : type exigeant l'eID, ou document déjà signé par eID (le PDF ne peut plus être réécrit).
    $eidOnly = $d->usesEid();
    $eidAvailable = ! $eidBlocked;
    $placements = collect([['id' => $s->id, 'label' => $me->signatureName(), 'page' => $s->page, 'x' => $s->position_x, 'y' => $s->position_y, 'width' => $s->width, 'height' => $s->height]]);
@endphp
<a class="back" href="{{ route('dashboard') }}"><x-icon name="arrow-left" />Accueil</a>
<div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
    <div><p class="eyebrow">{{ $d->document_type ?: 'Signature' }} · {{ $d->requester->name }} · avant le {{ $d->expires_at->format('d/m/Y') }}</p><h1 class="page-title mb-0">{{ $d->title }}</h1></div>
    <span class="small text-muted" data-save-state role="status" aria-live="polite"></span>
</div>
@if($batchPosition)<p class="eyebrow">Lot · document {{ $batchPosition[0] }} sur {{ $batchPosition[1] }}</p>@endif
@if($eidAvailable)
    <div class="mb-3">@include('partials.eid-help', ['mode' => 'auto'])</div>
@endif
@if(! $eidOnly && ! $me->signature_path)
    <x-alert type="warning">Pour signer sans eID, ajoutez d’abord votre image de signature. <a href="{{ route('profile.show') }}">Ajouter mon image de signature<x-icon name="arrow-right" class="link-icon" /></a></x-alert>
@endif
@if($eidOnly)<x-alert type="info">Ce document se signe uniquement avec la carte eID{{ $hasEidSignature ? ' : il porte déjà une signature eID.' : '.' }}</x-alert>@endif
@unless($eidAvailable)<x-alert type="warning">Signature eID indisponible pour l’instant : d’autres signataires doivent encore compléter des champs.</x-alert>@endunless
@unless($eidOnly)
<div class="d-flex flex-wrap align-items-center gap-2 mb-2"><span class="small text-muted">@if($s->fields->where('added_by_signer', false)->isNotEmpty())Complétez les cadres violets, puis signez. @endif Ajouter un champ :</span><button type="button" class="btn btn-sm btn-outline-primary" data-add-field="date"><x-icon name="calendar" />Date</button><button type="button" class="btn btn-sm btn-outline-primary" data-add-field="checkbox"><x-icon name="checkbox" />Case à cocher</button><button type="button" class="btn btn-sm btn-outline-primary" data-add-field="text"><x-icon name="cursor-text" />Texte libre</button></div>
@endunless
<div class="pdf-stage mb-3" data-pdf-stage data-single="true" data-movable="{{ $d->signer_can_move ? 'true' : 'false' }}" data-pdf-url="{{ route('documents.file',$d) }}" data-save-url="{{ route('signatures.position',$s) }}" data-placements='@json($placements)' data-field-mode="{{ $eidOnly ? '' : 'fill' }}" data-field-form="sign-form" data-fields='@json($s->fields->map->toEditorArray())' data-field-create-url="{{ route('signatures.fields.store', $s) }}" data-field-url-template="{{ route('signatures.fields.update', [$s, '__ID__']) }}"></div>
<div class="action-bar">
    <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#delegateModal"><x-icon name="user-share" />Déléguer</button>
        <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal"><x-icon name="thumb-down" />Refuser</button>
    </div>
    <div class="d-flex flex-wrap gap-2 ms-auto">
        @unless($eidOnly)<button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#signModal" data-open-sign="internal" @disabled(! $me->signature_path)><x-icon name="signature" />Signer sans eID</button>@endunless
        @if($eidAvailable)<button class="btn btn-coral btn-lg" data-bs-toggle="modal" data-bs-target="#signModal" data-open-sign="eid"><x-icon name="id" />Signer avec ma carte eID</button>@endif
    </div>
</div>

<div class="modal fade" id="signModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" id="sign-form" method="post" action="{{ route('signatures.sign',$s) }}" @if($eidAvailable) data-eid-sign data-prepare-url="{{ route('signatures.eid.prepare', $s) }}" data-finalize-url="{{ route('signatures.eid.finalize', $s) }}" data-failure-url="{{ route('signatures.eid.failure', $s) }}" @endif>@csrf
    <div class="band"><span class="band__title"><x-icon name="signature" />Mentions sous la signature</span></div>
    <div class="modal-body">
        <div class="form-check mb-2"><input type="hidden" name="show_name" value="0"><input class="form-check-input" type="checkbox" name="show_name" value="1" id="show_name" @checked(old('show_name', $me->sign_show_name ?? true))><label class="form-check-label" for="show_name">{{ $me->signatureName() }}</label></div>
        <div class="form-check mb-1"><input type="hidden" name="show_job_title" value="0"><input class="form-check-input" type="checkbox" name="show_job_title" value="1" id="show_job_title" @checked(old('show_job_title', ($me->sign_show_job_title ?? true) && filled($me->job_title)))><label class="form-check-label" for="show_job_title">Qualité</label></div>
        <input class="form-control form-control-sm mb-2 @error('job_title') is-invalid @enderror" name="job_title" maxlength="120" value="{{ old('job_title', $me->job_title) }}" placeholder="Directeur, professeur…" aria-label="Qualité">
        <div class="form-check"><input type="hidden" name="show_date" value="0"><input class="form-check-input" type="checkbox" name="show_date" value="1" id="show_date" @checked(old('show_date', $me->sign_show_date ?? true))><label class="form-check-label" for="show_date">Date et heure</label></div>
        <p class="form-label mt-3 mb-1" id="sign-preview-label">Aperçu</p>
        <div class="sign-preview" data-sign-preview data-mode="{{ $eidOnly ? 'eid' : 'internal' }}" data-name="{{ $me->signatureName() }}" aria-labelledby="sign-preview-label">
            @if($me->signature_path)<img src="{{ route('profile.signature.show', ['v' => $me->updated_at?->timestamp]) }}" alt="Votre image de signature">@endif
            <div data-preview-lines></div>
        </div>
        <div class="form-check mt-3"><input class="form-check-input @error('consent') is-invalid @enderror" type="checkbox" name="consent" value="1" id="consent" required><label class="form-check-label" for="consent">J’ai lu le document « {{ $d->title }} » et je confirme le signer.</label></div>
    </div>
    @if($eidAvailable)<p class="small text-muted px-3 mb-0" data-eid-progress data-sign-only="eid" role="status" aria-live="polite">Gardez la carte dans le lecteur : Web eID vous demandera votre code PIN de signature.</p>@endif
    <div class="modal-footer">
        <button class="btn btn-ghost-primary" type="button" data-bs-dismiss="modal"><x-icon name="arrow-left" />Retour</button>
        @unless($eidOnly)<button class="btn btn-primary" data-sign-only="internal" data-sign-mode="internal" @disabled(! $me->signature_path)><x-icon name="signature" />Signer sans eID</button>@endunless
        @if($eidAvailable)<button class="btn btn-coral" data-sign-submit data-sign-only="eid" data-sign-mode="eid"><x-icon name="id" />Signer avec la carte eID</button>@endif
    </div>
</form></div></div>

<div class="modal fade" id="rejectModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post" action="{{ route('signatures.reject',$s) }}">@csrf
    <div class="modal-status bg-danger"></div>
    <div class="band"><span class="band__title"><x-icon name="thumb-down" />Refuser de signer</span></div>
    <div class="modal-body"><p class="small text-muted">Le demandeur sera prévenu par e-mail ; votre motif est conservé dans le journal de preuve.</p><label class="form-label" for="reason">Motif</label><textarea class="form-control" id="reason" name="reason" required maxlength="1000"></textarea></div>
    <div class="modal-footer"><button class="btn btn-ghost-primary" type="button" data-bs-dismiss="modal"><x-icon name="arrow-left" />Retour</button><button class="btn btn-danger"><x-icon name="thumb-down" />Refuser</button></div>
</form></div></div>

<div class="modal fade" id="delegateModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post" action="{{ route('signatures.delegate',$s) }}">@csrf
    <div class="band"><span class="band__title"><x-icon name="user-share" />Déléguer la signature</span></div>
    <div class="modal-body"><label class="form-label" for="delegate-email">À</label><input class="form-control" id="delegate-email" type="email" name="email" required placeholder="{{ \App\Models\Setting::emailPlaceholder() }}" list="known-users" autocomplete="off">@include('partials.known-users-datalist')</div>
    <div class="modal-footer"><button class="btn btn-ghost-primary" type="button" data-bs-dismiss="modal"><x-icon name="arrow-left" />Retour</button><button class="btn btn-primary"><x-icon name="user-share" />Déléguer</button></div>
</form></div></div>
@endsection
