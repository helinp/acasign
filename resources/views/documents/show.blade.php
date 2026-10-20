@extends('layouts.app')
@section('title', $document->title)
@section('content')
@php
    $isRequester = $document->requester_id === auth()->id();
    $participant = $isRequester || $document->signatureRequests->contains('signer_email', strtolower(auth()->user()->email));
@endphp
<a class="back" href="{{ url()->previous() !== url()->current() ? url()->previous() : route('documents.index') }}"><x-icon name="arrow-left" />Retour</a>
<div class="row g-4 g-lg-5"><div class="col-lg-8">
    <p class="eyebrow">{{ $document->document_type ?: 'Document' }}{{ $document->usesEid() ? ' · signature eID' : '' }} · {{ $document->requester->name }} · avant le {{ $document->expires_at?->format('d/m/Y') }}</p>
    <div class="d-flex align-items-start justify-content-between gap-3 mb-3"><h1 class="page-title mb-0">{{ $document->title }}</h1><x-status :status="$document->status" /></div>
    <div class="band"><span class="band__title"><x-icon name="users" />Signataires</span></div>
    <div class="list-rows mb-3">
        @foreach($document->signatureRequests as $signature)
            <div><span @class(['num', 'num--done' => $signature->status === 'signed'])>@if($signature->status === 'signed')<x-icon name="check" label="Signé" />@else{{ $loop->iteration }}@endif</span><div class="flex-grow-1"><strong>{{ $signature->signer_name }}</strong><div class="small text-muted">{{ $signature->signer_email }}@if($signature->signed_at) · {{ $signature->signed_at->format('d/m/Y H:i') }}@endif @if($signature->certificate_info) · carte eID : {{ $signature->certificate_info['subject'] ?? '' }}@endif</div></div><x-status :status="$signature->status" /></div>
        @endforeach
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        @if($participant)<a href="{{ route('documents.file', [$document, 'download' => 1]) }}" class="btn btn-primary"><x-icon name="download" />Télécharger</a><a href="{{ route('documents.file',$document) }}" class="btn btn-outline-primary" target="_blank" rel="noopener"><x-icon name="external-link" />Ouvrir le PDF<span class="visually-hidden"> (nouvel onglet)</span></a>@endif
        @if($isRequester && $document->status === 'pending')<a href="{{ route('documents.edit',$document) }}" class="btn btn-outline-primary"><x-icon name="drag-drop" />Modifier le placement</a>@endif
        {{-- Actions destructives : à l'écart des actions courantes, toujours confirmées. --}}
        @if($isRequester && in_array($document->status, ['pending', 'signed'], true))
            <div class="ms-auto ps-3 border-start">
                @if($document->status === 'pending')<form method="post" action="{{ route('documents.cancel',$document) }}" data-confirm="Annuler la demande « {{ $document->title }} » ? Les signataires ne pourront plus signer.">@csrf @method('DELETE')<button class="btn btn-outline-danger"><x-icon name="circle-x" />Annuler la demande</button></form>@endif
                @if($document->status === 'signed')<button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#revokeModal"><x-icon name="ban" />Révoquer le document</button>@endif
            </div>
        @endif
    </div>
</div><aside class="col-lg-4">
    @php($events = $document->auditEvents->sortByDesc('created_at'))
    @php($routine = $events->filter->isRoutine())
    <div class="band"><span class="band__title"><x-icon name="history" />Journal de preuve</span></div>
    <div class="panel">
        <ol class="timeline proof-timeline mb-0">
            @foreach($events->reject->isRoutine() as $event)
                <li class="timeline-event"><div class="timeline-event-icon"><x-icon :name="$event->icon()" /></div><div class="timeline-event-card"><strong class="d-block">{{ $event->label() }}</strong><span class="small text-muted"><time datetime="{{ $event->created_at->toIso8601String() }}">{{ $event->created_at->format('d/m/Y H:i') }}</time> · {{ $event->actor?->name ?? 'Système' }}</span></div></li>
            @endforeach
        </ol>
        @if($routine->isNotEmpty())
            <details class="mt-2 small">
                <summary><x-icon name="eye" />Consultations et téléchargements ({{ $routine->count() }})</summary>
                <ul class="list-unstyled mt-2 mb-0">@foreach($routine as $event)<li>{{ $event->label() }} · {{ $event->created_at->format('d/m/Y H:i') }} · {{ $event->actor?->name ?? 'Système' }}</li>@endforeach</ul>
            </details>
        @endif
        <details class="mt-2 small">
            <summary><x-icon name="lock" />Détails techniques (empreintes)</summary>
            <ul class="list-unstyled mt-2 mb-0">@foreach($events as $event)<li><code class="hash">{{ $event->event }} · {{ $event->hash }}</code></li>@endforeach</ul>
        </details>
    </div>
</aside></div>
<div class="modal fade" id="revokeModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post" action="{{ route('documents.revoke',$document) }}">@csrf
    <div class="modal-status bg-danger"></div>
    <div class="band"><span class="band__title"><x-icon name="ban" />Révoquer le document</span></div>
    <div class="modal-body"><p class="small text-muted">Le document restera consultable mais sera signalé comme révoqué lors de toute vérification.</p><label class="form-label" for="revoke-reason">Motif</label><textarea class="form-control" id="revoke-reason" name="reason" required maxlength="1000"></textarea></div>
    <div class="modal-footer"><button class="btn btn-ghost-primary" type="button" data-bs-dismiss="modal"><x-icon name="arrow-left" />Retour</button><button class="btn btn-danger"><x-icon name="ban" />Révoquer</button></div>
</form></div></div>
@endsection
