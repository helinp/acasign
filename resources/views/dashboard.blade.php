@extends('layouts.app')
@section('title', 'Accueil')
@section('content')
@php($canSend = auth()->user()->can_send || auth()->user()->isAdmin())
@unless(auth()->user()->signature_path)<div class="alert alert-warning">Pour signer, ajoutez d’abord votre image de signature. <a href="{{ route('profile.show') }}"><strong>Mon profil →</strong></a></div>@endunless

<section class="mb-5">
    <div class="band">À signer <span>{{ $toSign->count() }}</span></div>
    <div class="list-rows">
    @forelse($toSign as $document)
        @php($request = $document->signatureRequests->firstWhere('signer_email', strtolower(auth()->user()->email)))
        <a href="{{ route('signatures.show', $request) }}" class="row-link">
            <div class="flex-grow-1"><strong class="d-block">{{ $document->title }}</strong><span class="small text-muted">{{ $document->document_type ? $document->document_type.' · ' : '' }}{{ $document->requester->name }} · avant le {{ $document->expires_at->format('d/m/Y') }}</span></div>
            <span class="btn btn-sm btn-primary">Signer</span>
        </a>
    @empty
        <div class="text-muted">Rien à signer pour le moment.</div>
    @endforelse
    </div>
</section>

@if($canSend)
<section class="mb-4">
    <div class="band">Mes envois en cours <span>{{ $sent->count() }}</span></div>
    <div class="list-rows">
    @forelse($sent as $document)
        @php($signedCount = $document->signatureRequests->where('status', 'signed')->count())
        @php($total = $document->signatureRequests->whereIn('status', ['pending', 'signed'])->count())
        <a href="{{ route('documents.show', $document) }}" class="row-link">
            <div class="flex-grow-1"><strong class="d-block">{{ $document->title }}</strong><span class="small text-muted">{{ $document->document_type ? $document->document_type.' · ' : '' }}envoyé le {{ $document->created_at->format('d/m/Y') }}</span></div>
            <span class="small fw-semibold">{{ $signedCount }}/{{ $total }} signé{{ $signedCount > 1 ? 's' : '' }}</span>
        </a>
    @empty
        <div class="text-muted">Aucun envoi en cours. <a href="{{ route('documents.create') }}"><strong>Nouvelle demande</strong></a></div>
    @endforelse
    </div>
</section>
@endif
<p><a href="{{ route('documents.index') }}"><strong>Tous mes documents →</strong></a></p>
@endsection
