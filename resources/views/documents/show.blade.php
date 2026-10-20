@extends('layouts.app')
@section('title', $document->title)
@section('content')
@php
    $labels = ['pending'=>'En attente','signed'=>'Signé','rejected'=>'Refusé','cancelled'=>'Annulé','expired'=>'Expiré','revoked'=>'Révoqué','delegated'=>'Délégué'];
    $isRequester = $document->requester_id === auth()->id();
    $participant = $isRequester || $document->signatureRequests->contains('signer_email', strtolower(auth()->user()->email));
@endphp
<a class="back" href="{{ url()->previous() !== url()->current() ? url()->previous() : route('documents.index') }}">← Retour</a>
<div class="row g-4 g-lg-5"><div class="col-lg-8">
    <p class="eyebrow">{{ $document->document_type ?: 'Document' }}{{ $document->usesEid() ? ' · signature eID' : '' }} · {{ $document->requester->name }} · avant le {{ $document->expires_at?->format('d/m/Y') }}</p>
    <div class="d-flex align-items-start justify-content-between gap-3 mb-3"><h1 class="page-title mb-0">{{ $document->title }}</h1><span class="status-badge status-{{ $document->status }}">{{ $labels[$document->status] ?? $document->status }}</span></div>
    <div class="band">Signataires</div>
    <div class="list-rows mb-3">
        @foreach($document->signatureRequests as $signature)
            <div><span @class(['num', 'num--done' => $signature->status === 'signed'])>{{ $signature->status === 'signed' ? '✓' : $loop->iteration }}</span><div class="flex-grow-1"><strong>{{ $signature->signer_name }}</strong><div class="small text-muted">{{ $signature->signer_email }}@if($signature->signed_at) · {{ $signature->signed_at->format('d/m/Y H:i') }}@endif @if($signature->certificate_info) · carte eID : {{ $signature->certificate_info['subject'] ?? '' }}@endif</div></div><span class="status-badge status-{{ $signature->status }}">{{ $labels[$signature->status] ?? $signature->status }}</span></div>
        @endforeach
    </div>
    <div class="d-flex flex-wrap gap-2">
        @if($participant)<a href="{{ route('documents.file', [$document, 'download' => 1]) }}" class="btn btn-primary">Télécharger</a><a href="{{ route('documents.file',$document) }}" class="btn btn-outline-primary" target="_blank" rel="noopener">Voir</a>@endif
        @if($isRequester && $document->status === 'pending')<a href="{{ route('documents.edit',$document) }}" class="btn btn-outline-primary">Placement</a><form method="post" action="{{ route('documents.cancel',$document) }}">@csrf @method('DELETE')<button class="btn btn-outline-danger">Annuler</button></form>@endif
        @if($isRequester && $document->status === 'signed')<button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#revokeModal">Révoquer</button>@endif
    </div>
</div><aside class="col-lg-4">
    <div class="band">Journal</div>
    <div class="panel"><div class="proof-rail">
        @foreach($document->auditEvents->sortByDesc('created_at') as $event)<div class="proof-event"><strong class="d-block">{{ str_replace('.', ' · ', $event->event) }}</strong><span class="small text-muted">{{ $event->created_at->format('d/m/Y H:i') }} · {{ $event->actor?->name ?? 'Système' }}</span><code class="d-block">{{ Str::limit($event->hash, 22) }}</code></div>@endforeach
    </div></div>
</aside></div>
<div class="modal fade" id="revokeModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post" action="{{ route('documents.revoke',$document) }}">@csrf
    <div class="band">Révoquer</div>
    <div class="modal-body"><label class="form-label" for="revoke-reason">Motif</label><textarea class="form-control" id="revoke-reason" name="reason" required maxlength="1000"></textarea></div>
    <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Retour</button><button class="btn btn-danger">Révoquer</button></div>
</form></div></div>
@endsection
