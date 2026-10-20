@extends('layouts.app')
@section('title', 'Accueil')
@section('content')
@php($canSend = auth()->user()->can_send || auth()->user()->isAdmin())
@unless(auth()->user()->signature_path)<x-alert type="warning">Pour signer, ajoutez d’abord votre image de signature. <a href="{{ route('profile.show') }}">Ajouter mon image de signature<x-icon name="arrow-right" class="link-icon" /></a></x-alert>@endunless

<section class="mb-5" aria-labelledby="to-sign-title">
    <h2 class="band" id="to-sign-title"><span class="band__title"><x-icon name="signature" />À signer</span> <span>{{ $toSign->count() }}</span></h2>
    <div class="list-rows">
    @forelse($toSign as $document)
        @php($request = $document->signatureRequests->firstWhere('signer_email', strtolower(auth()->user()->email)))
        <a href="{{ route('signatures.show', $request) }}" class="row-link">
            <span class="row-icon"><x-icon name="signature" /></span>
            <div class="flex-grow-1 min-w-0">
                <strong class="row-title d-block">{{ $document->title }}</strong>
                <span class="row-meta">@if($document->document_type)<span>{{ $document->document_type }}</span>@endif<span><x-icon name="user-circle" />{{ $document->requester->name }}</span><span><x-icon name="calendar" />avant le {{ $document->expires_at->format('d/m/Y') }}</span>@if($document->expires_at->lte(now()->addDays(3)))<span class="due-soon"><x-icon name="clock-exclamation" />{{ $document->expires_at->isToday() ? 'Aujourd’hui' : 'Échéance dans '.max(1, (int) ceil(now()->diffInDays($document->expires_at))).' j' }}</span>@endif</span>
            </div>
            <span class="btn btn-sm btn-primary d-none d-sm-inline-flex"><x-icon name="signature" />Signer</span>
            <x-icon name="chevron-right" class="row-chevron d-sm-none" />
        </a>
    @empty
        <x-empty icon="circle-check" title="Rien à signer pour le moment." />
    @endforelse
    </div>
</section>

@if($canSend)
<section class="mb-4" aria-labelledby="sent-title">
    <h2 class="band" id="sent-title"><span class="band__title"><x-icon name="send" />Mes envois en cours</span> <span>{{ $sent->count() }}</span></h2>
    <div class="list-rows">
    @forelse($sent as $document)
        @php($signedCount = $document->signatureRequests->where('status', 'signed')->count())
        @php($total = $document->signatureRequests->whereIn('status', ['pending', 'signed'])->count())
        <a href="{{ route('documents.show', $document) }}" class="row-link">
            <span class="row-icon"><x-icon name="send" /></span>
            <div class="flex-grow-1 min-w-0">
                <strong class="row-title d-block">{{ $document->title }}</strong>
                <span class="row-meta">@if($document->document_type)<span>{{ $document->document_type }}</span>@endif<span><x-icon name="calendar" />envoyé le {{ $document->created_at->format('d/m/Y') }}</span></span>
            </div>
            <span class="row-progress" title="{{ $signedCount }} signature{{ $signedCount > 1 ? 's' : '' }} sur {{ $total }}">
                <span class="small fw-semibold">{{ $signedCount }}/{{ $total }} signé{{ $signedCount > 1 ? 's' : '' }}</span>
                <span class="progress progress-sm" aria-hidden="true"><span class="progress-bar" style="width: {{ $total ? round($signedCount / $total * 100) : 0 }}%"></span></span>
            </span>
            <x-icon name="chevron-right" class="row-chevron" />
        </a>
    @empty
        <x-empty icon="send" title="Aucun envoi en cours."><a href="{{ route('documents.create') }}" class="btn btn-primary"><x-icon name="file-plus" />Nouvelle demande</a></x-empty>
    @endforelse
    </div>
</section>
@endif
<section class="mb-4" aria-labelledby="recent-signed-title">
    <h2 class="band" id="recent-signed-title"><span class="band__title"><x-icon name="circle-check" />Derniers documents signés</span>
        <span class="band__choice" role="group" aria-label="Nombre de documents affichés">@foreach($recentSizes as $size)<a href="{{ route('dashboard', $size === $recentSizes[0] ? [] : ['recent' => $size]) }}#recent-signed-title" @class(['is-on' => $recentCount === $size]) @if($recentCount === $size) aria-current="true" @endif>{{ $size }}</a>@endforeach</span>
    </h2>
    <div class="list-rows">
    @forelse($recentSigned as $document)
        <div class="row-link-wrap">
            <span class="row-icon row-icon--done"><x-icon name="circle-check" /></span>
            <div class="flex-grow-1 min-w-0">
                <a class="row-title d-block" href="{{ route('documents.show', $document) }}">{{ $document->title }}</a>
                <span class="row-meta">@if($document->document_type)<span>{{ $document->document_type }}</span>@endif<span><x-icon name="users" />{{ $document->signatureRequests->where('status', 'signed')->pluck('signer_name')->join(', ') }}</span><span><x-icon name="calendar" />signé le {{ $document->completed_at?->format('d/m/Y') }}</span></span>
            </div>
            <a class="btn btn-sm btn-outline-primary" href="{{ route('documents.file', [$document, 'download' => 1]) }}"><x-icon name="download" />PDF<span class="visually-hidden"> signé : {{ $document->title }}</span></a>
        </div>
    @empty
        <x-empty icon="files" title="Aucun document signé pour le moment." />
    @endforelse
    </div>
</section>
<p><a href="{{ route('documents.index', ['status' => 'signed']) }}">Voir tous les documents signés<x-icon name="arrow-right" class="link-icon" /></a></p>
@endsection
