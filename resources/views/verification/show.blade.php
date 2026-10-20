@extends('layouts.app')
@section('title', 'Vérification')
@section('content')
@php
    $valid = $integrity && $signatureRequest->status === 'signed' && ! in_array($document->status, ['revoked', 'cancelled']);
@endphp
<div class="mx-auto" style="max-width:720px">
    <div class="brand mb-4">@if($logoUrl)<img src="{{ $logoUrl }}" alt="">@endif<span class="brand__name">{{ $entityName }}</span></div>
    <p class="mb-2"><span @class(['seal', 'seal--ok' => $valid, 'seal--ko' => ! $valid])><x-icon :name="$valid ? 'shield-check' : 'shield-x'" />{{ $valid ? 'Signature vérifiée' : 'Vérification en alerte' }}</span></p>
    @unless($valid)<p class="text-muted">Document révoqué, incomplet ou intégrité non confirmée.</p>@endunless
    <div class="panel mt-3"><dl class="row g-2 mb-0">
        <dt class="col-sm-4 text-muted">Document</dt><dd class="col-sm-8 fw-semibold">{{ $document->title }}</dd>
        <dt class="col-sm-4 text-muted">Signataire</dt><dd class="col-sm-8">{{ $signatureRequest->signer_name }}@if($signatureRequest->signer_job_title) · {{ $signatureRequest->signer_job_title }}@endif</dd>
        <dt class="col-sm-4 text-muted">Signé le</dt><dd class="col-sm-8">{{ $signatureRequest->signed_at?->format('d/m/Y à H:i:s') ?: 'En attente' }}</dd>
        <dt class="col-sm-4 text-muted">État</dt><dd class="col-sm-8"><x-status :status="$document->status" /></dd>
        <dt class="col-sm-4 text-muted">Intégrité</dt><dd class="col-sm-8">{{ $integrity ? 'Conforme' : 'Non vérifiable' }}</dd>
        <dt class="col-sm-4 text-muted">SHA-256</dt><dd class="col-sm-8 hash">{{ $currentHash ?: 'Fichier arrivé à échéance de conservation' }}</dd>
        @if($document->revoked_at)<dt class="col-sm-4 text-muted">Révoqué le</dt><dd class="col-sm-8">{{ $document->revoked_at->format('d/m/Y H:i') }} · {{ $document->revocation_reason }}</dd>@endif
    </dl></div>
    <p class="small text-muted mt-3">Validation interne, pas une signature qualifiée eIDAS.</p>
</div>
@endsection
