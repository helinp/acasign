@extends('layouts.app')
@section('title', 'Documents')
@section('content')
@php
    $tabs = ['signed' => 'Signés', 'pending' => 'En cours', 'closed' => 'Refusés · annulés'];
    $labels = ['pending'=>'En attente','signed'=>'Signé','rejected'=>'Refusé','cancelled'=>'Annulé','expired'=>'Expiré','revoked'=>'Révoqué'];
    $query = array_filter(['q' => $filters['q'] ?? null, 'type' => $filters['type'] ?? null, 'role' => $filters['role'] ?? null]);
@endphp
<div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
    <h1 class="page-title mb-0">Documents</h1>
    <a href="{{ route('documents.export') }}" class="btn btn-sm btn-outline-secondary">Export CSV</a>
</div>
<nav class="tabs mb-3" aria-label="Statut">
    @foreach($tabs as $key => $label)<a href="{{ route('documents.index', [...$query, 'status' => $key]) }}" @class(['is-on' => $tab === $key])>{{ $label }}</a>@endforeach
</nav>
<form method="get" class="d-flex flex-wrap gap-2 mb-3">
    <input type="hidden" name="status" value="{{ $tab }}">
    <input class="form-control" style="max-width:18rem" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Rechercher un titre" aria-label="Rechercher un titre">
    <select class="form-select" style="max-width:12rem" name="type" aria-label="Type" onchange="this.form.submit()"><option value="">Tous les types</option>@foreach($documentTypes as $type)<option @selected(($filters['type'] ?? null) === $type)>{{ $type }}</option>@endforeach</select>
    <select class="form-select" style="max-width:14rem" name="role" aria-label="Rôle" onchange="this.form.submit()"><option value="">Envoyés et reçus</option><option value="requester" @selected(($filters['role'] ?? null) === 'requester')>Envoyés par moi</option><option value="signer" @selected(($filters['role'] ?? null) === 'signer')>Reçus pour signature</option></select>
    <button class="btn btn-outline-primary">Rechercher</button>
    @if($query)<a class="btn btn-light" href="{{ route('documents.index', ['status' => $tab]) }}">Effacer</a>@endif
</form>
<div class="table-responsive border"><table class="table align-middle"><thead><tr><th>Document</th><th>Type</th><th>Demandeur</th><th>{{ $tab === 'signed' ? 'Signé le' : 'Créé le' }}</th><th></th></tr></thead><tbody>
@forelse($documents as $document)
    <tr data-href="{{ route('documents.show', $document) }}">
        <td><strong>{{ $document->title }}</strong>@if($tab === 'closed') <span class="status-badge status-{{ $document->status }}">{{ $labels[$document->status] ?? $document->status }}</span>@endif<div class="small text-muted">{{ $document->signatureRequests->whereIn('status', ['pending', 'signed'])->pluck('signer_name')->join(', ') }}</div></td>
        <td>{{ $document->document_type ?: '—' }}</td>
        <td>{{ $document->requester->name }}</td>
        <td>{{ ($tab === 'signed' ? $document->completed_at : $document->created_at)?->format('d/m/Y') }}</td>
        <td class="text-end text-nowrap">@if($tab === 'signed')<a class="btn btn-sm btn-primary" href="{{ route('documents.file', [$document, 'download' => 1]) }}">PDF signé</a>@else<a class="btn btn-sm btn-outline-primary" href="{{ route('documents.show', $document) }}">Ouvrir</a>@endif</td>
    </tr>
@empty
    <tr><td colspan="5" class="text-muted">Aucun document{{ $query ? ' pour cette recherche' : '' }}.</td></tr>
@endforelse
</tbody></table></div>
<div class="mt-3">{{ $documents->links() }}</div>
@endsection
