@extends('layouts.app')
@section('title', 'Documents')
@section('content')
@php
    $tabs = ['signed' => ['Signés', 'circle-check'], 'pending' => ['En cours', 'clock'], 'closed' => ['Refusés · annulés', 'circle-x']];
    $sorts = ['recent' => 'Plus récents', 'oldest' => 'Plus anciens', 'title' => 'Titre (A → Z)', 'title_desc' => 'Titre (Z → A)', 'requester' => 'Demandeur', 'deadline' => 'Échéance'];
    $periods = [7 => '7 jours', 30 => '30 jours', 90 => '3 mois', 365 => '12 mois'];
    $roles = ['requester' => 'Envoyés par moi', 'signer' => 'Reçus pour signature'];
    $dateLabel = $tab === 'signed' ? 'Signé le' : 'Créé le';
    // Paramètres conservés par les liens (onglets, tri, retrait d'un filtre).
    $keep = [...$activeFilters, 'status' => $tab, 'sort' => $sort, 'per_page' => $perPage === 25 ? null : $perPage];
    $url = fn (array $changes) => route('documents.index', array_filter([...$keep, ...$changes], fn ($value) => filled($value)));
    // En-tête triable : un clic trie, un second inverse l'ordre. Retourne [lien, icône, actif].
    $sortHeader = function (string $asc, string $desc) use ($sort, $url): array {
        $current = in_array($sort, [$asc, $desc], true);
        $icon = ! $current ? 'arrows-sort' : (in_array($sort, ['title', 'requester', 'oldest'], true) ? 'arrow-up' : 'arrow-down');
        return [$url(['sort' => $sort === $asc ? $desc : $asc, 'page' => null]), $icon, $current];
    };
    $chips = [
        'q' => isset($activeFilters['q']) ? 'Titre : « '.$activeFilters['q'].' »' : null,
        'person' => isset($activeFilters['person']) ? 'Personne : '.$activeFilters['person'] : null,
        'type' => $activeFilters['type'] ?? null,
        'role' => isset($activeFilters['role']) ? $roles[$activeFilters['role']] : null,
        'period' => isset($activeFilters['period']) ? 'Derniers '.$periods[$activeFilters['period']] : null,
        'from' => isset($activeFilters['from']) ? 'Depuis le '.\Illuminate\Support\Carbon::parse($activeFilters['from'])->format('d/m/Y') : null,
        'to' => isset($activeFilters['to']) ? 'Jusqu’au '.\Illuminate\Support\Carbon::parse($activeFilters['to'])->format('d/m/Y') : null,
    ];
@endphp
<div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
    <h1 class="page-title mb-0">Documents</h1>
    <a href="{{ route('documents.export', array_filter($keep)) }}" class="btn btn-sm btn-outline-primary"><x-icon name="table-export" />Exporter cette liste (CSV)</a>
</div>
<nav class="tabs mb-3" aria-label="Statut">
    @foreach($tabs as $key => [$label, $icon])<a href="{{ $url(['status' => $key, 'page' => null, 'sort' => in_array($sort, ['deadline']) && $key !== 'pending' ? null : $sort]) }}" @class(['is-on' => $tab === $key]) @if($tab === $key) aria-current="page" @endif><x-icon :name="$icon" />{{ $label }} <span class="tabs__count">{{ $counts[$key] }}</span></a>@endforeach
</nav>

<form method="get" class="filters" role="search" aria-label="Filtrer les documents">
    <input type="hidden" name="status" value="{{ $tab }}">
    <input type="hidden" name="sort" value="{{ $sort }}">
    @if($perPage !== 25)<input type="hidden" name="per_page" value="{{ $perPage }}">@endif
    <div class="row g-2">
        <div class="col-md-6 col-lg-3">
            <label class="form-label small mb-1" for="filter-q">Titre</label>
            <div class="input-icon"><span class="input-icon-addon"><x-icon name="search" /></span><input class="form-control" id="filter-q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Rechercher un titre"></div>
        </div>
        <div class="col-md-6 col-lg-3">
            <label class="form-label small mb-1" for="filter-person">Personne</label>
            <div class="input-icon"><span class="input-icon-addon"><x-icon name="user-search" /></span><input class="form-control" id="filter-person" type="search" name="person" value="{{ $filters['person'] ?? '' }}" placeholder="Nom ou e-mail" list="known-people" autocomplete="off"></div>
            <datalist id="known-people">@foreach($knownPeople as $person)<option value="{{ $person }}">@endforeach</datalist>
        </div>
        <div class="col-6 col-lg-3">
            <label class="form-label small mb-1" for="filter-type">Type</label>
            <select class="form-select" id="filter-type" name="type" data-auto-submit><option value="">Tous les types</option>@foreach($documentTypes as $type)<option @selected(($filters['type'] ?? null) === $type)>{{ $type }}</option>@endforeach</select>
        </div>
        <div class="col-6 col-lg-3">
            <label class="form-label small mb-1" for="filter-role">Rôle</label>
            <select class="form-select" id="filter-role" name="role" data-auto-submit><option value="">Envoyés et reçus</option>@foreach($roles as $key => $label)<option value="{{ $key }}" @selected(($filters['role'] ?? null) === $key)>{{ $label }}</option>@endforeach</select>
        </div>
    </div>
    <div class="d-flex flex-wrap align-items-end gap-2 mt-2">
        <fieldset class="me-2">
            <legend class="form-label small mb-1">{{ $tab === 'signed' ? 'Signés depuis' : 'Créés depuis' }}</legend>
            <div class="tabs tabs--sm">
                <a href="{{ $url(['period' => null, 'from' => null, 'to' => null, 'page' => null]) }}" @class(['is-on' => empty($filters['period']) && empty($filters['from']) && empty($filters['to'])])>Toujours</a>
                @foreach($periods as $days => $label)<a href="{{ $url(['period' => $days, 'from' => null, 'to' => null, 'page' => null]) }}" @class(['is-on' => (int) ($filters['period'] ?? 0) === $days])>{{ $label }}</a>@endforeach
            </div>
        </fieldset>
        <div><label class="form-label small mb-1" for="filter-from">Du</label><input class="form-control form-control-sm @error('from') is-invalid @enderror" type="date" id="filter-from" name="from" value="{{ $filters['from'] ?? '' }}"></div>
        <div><label class="form-label small mb-1" for="filter-to">Au</label><input class="form-control form-control-sm @error('to') is-invalid @enderror" type="date" id="filter-to" name="to" value="{{ $filters['to'] ?? '' }}"></div>
        @if(! empty($filters['period']))<input type="hidden" name="period" value="{{ $filters['period'] }}" data-period>@endif
        <button class="btn btn-sm btn-primary"><x-icon name="adjustments-horizontal" />Filtrer</button>
        @if($activeFilters)<a class="btn btn-sm btn-ghost-primary" href="{{ route('documents.index', ['status' => $tab]) }}"><x-icon name="filter-off" />Tout effacer</a>@endif
    </div>
</form>

@if($activeFilters)
<ul class="filter-chips" aria-label="Filtres actifs">
    @foreach(array_filter($chips) as $key => $label)
        <li><span>{{ $label }}</span><a href="{{ $url([$key => null, 'page' => null]) }}" aria-label="Retirer le filtre {{ $label }}" title="Retirer ce filtre"><x-icon name="x" /></a></li>
    @endforeach
</ul>
@endif

<div class="list-toolbar">
    <p class="mb-0" role="status"><strong>{{ $documents->total() }}</strong> document{{ $documents->total() > 1 ? 's' : '' }}@if($documents->total() > $documents->count()) · {{ $documents->firstItem() }}–{{ $documents->lastItem() }} affichés @endif</p>
    <div class="d-flex flex-wrap align-items-center gap-2 ms-auto">
        <form method="get" class="d-flex align-items-center gap-2">
            @foreach(array_filter([...$activeFilters, 'status' => $tab, 'per_page' => $perPage === 25 ? null : $perPage]) as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
            <label class="small text-nowrap mb-0" for="sort">Trier par</label>
            <select class="form-select form-select-sm w-auto" id="sort" name="sort" data-auto-submit>@foreach($sorts as $key => $label)@if($key !== 'deadline' || $tab === 'pending')<option value="{{ $key }}" @selected($sort === $key)>{{ $label }}</option>@endif @endforeach</select>
        </form>
        @if($tab === 'signed' && $documents->total() > 0)
            <form method="post" action="{{ route('documents.archive') }}" id="archive-form" class="d-flex gap-2">@csrf
                @foreach([...$activeFilters, 'sort' => $sort] as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
                <button class="btn btn-sm btn-primary" name="selection" value="1" data-archive-selected><x-icon name="file-zip" />Télécharger la sélection<span data-archive-count></span></button>
                @if($documents->total() <= 200)<button class="btn btn-sm btn-outline-primary" data-archive-all><x-icon name="download" />Tout télécharger ({{ $documents->total() }})</button>@endif
            </form>
        @endif
    </div>
</div>

<div class="card"><table class="table card-table align-middle table-stack">
    <thead><tr>
        @if($tab === 'signed')<th scope="col" class="w-1"><input class="form-check-input" type="checkbox" data-select-all aria-label="Tout sélectionner sur cette page"></th>@endif
        @foreach([['Document', 'title', 'title_desc'], ['Signataires', null, null], ['Type', null, null], ['Demandeur', 'requester', 'requester'], [$dateLabel, 'recent', 'oldest']] as [$label, $asc, $desc])
            @if($asc)
                @php([$href, $icon, $current] = $sortHeader($asc, $desc))
                <th scope="col" @if($current) aria-sort="{{ in_array($sort, ['title', 'requester', 'oldest'], true) ? 'ascending' : 'descending' }}" @endif><a @class(['sort-link', 'is-on' => $current]) href="{{ $href }}">{{ $label }}<x-icon :name="$icon" class="ms-1" /></a></th>
            @else
                <th scope="col">{{ $label }}</th>
            @endif
        @endforeach
        <th scope="col"><span class="visually-hidden">Actions</span></th>
    </tr></thead>
    <tbody>
    @forelse($documents as $document)
        @php($date = $tab === 'signed' ? $document->completed_at : $document->created_at)
        <tr data-href="{{ route('documents.show', $document) }}">
            @if($tab === 'signed')<td class="w-1"><input class="form-check-input" type="checkbox" name="documents[]" value="{{ $document->id }}" form="archive-form" data-select-row aria-label="Sélectionner {{ $document->title }}"></td>@endif
            <td data-label="Document"><a class="row-title" href="{{ route('documents.show', $document) }}">{{ $document->title }}</a>@if($tab === 'closed') <x-status :status="$document->status" />@endif</td>
            <td data-label="Signataires" class="small">{{ $document->signatureRequests->whereIn('status', ['pending', 'signed'])->pluck('signer_name')->join(', ') ?: '—' }}</td>
            <td data-label="Type">{{ $document->document_type ?: '—' }}</td>
            <td data-label="Demandeur">{{ $document->requester->name }}</td>
            <td data-label="{{ $dateLabel }}" class="text-nowrap"><time datetime="{{ $date?->toIso8601String() }}" title="{{ $date?->format('d/m/Y H:i') }}">{{ $date?->format('d/m/Y') }}</time></td>
            <td class="text-end text-nowrap">@if($tab === 'signed')<a class="btn btn-sm btn-outline-primary" href="{{ route('documents.file', [$document, 'download' => 1]) }}"><x-icon name="download" />PDF<span class="visually-hidden"> signé : {{ $document->title }}</span></a>@else<x-icon name="chevron-right" class="row-chevron" />@endif</td>
        </tr>
    @empty
        <tr><td colspan="7"><x-empty :icon="$activeFilters ? 'search' : 'files'" :title="'Aucun document'.($activeFilters ? ' pour ces filtres' : '').'.'">@if($activeFilters)<a class="btn btn-sm btn-ghost-primary" href="{{ route('documents.index', ['status' => $tab]) }}"><x-icon name="filter-off" />Effacer les filtres</a>@endif</x-empty></td></tr>
    @endforelse
    </tbody>
</table></div>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3">
    <div>{{ $documents->onEachSide(1)->links() }}</div>
    @if($documents->total() > 25)
    <form method="get" class="d-flex align-items-center gap-2">
        @foreach(array_filter([...$activeFilters, 'status' => $tab, 'sort' => $sort]) as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
        <label class="small text-nowrap mb-0" for="per_page">Par page</label>
        <select class="form-select form-select-sm w-auto" id="per_page" name="per_page" data-auto-submit>@foreach([25, 50, 100] as $size)<option @selected($perPage === $size)>{{ $size }}</option>@endforeach</select>
    </form>
    @endif
</div>
@endsection
