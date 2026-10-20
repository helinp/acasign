@extends('layouts.app')
@section('title', 'Placement')
@section('content')
@php($placements = $document->signatureRequests->map(fn($s) => ['id'=>$s->id,'label'=>$s->signer_name,'page'=>$s->page,'x'=>$s->position_x,'y'=>$s->position_y,'width'=>$s->width,'height'=>$s->height]))
<div class="steps" aria-label="Étapes"><span class="is-done">1 · Document</span><span class="is-on">2 · Placement</span></div>
@if($batch->count() > 1)<p class="eyebrow">Lot · document {{ $position + 1 }} sur {{ $batch->count() }}</p>@endif
@if($document->usesEid())<div class="alert alert-warning py-2">Signature par carte eID : les champs à compléter ne sont pas disponibles pour ce document.</div>@endif
<div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
    <div><h1 class="page-title mb-1">{{ $document->title }}</h1><p class="small text-muted mb-0">Glissez chaque cadre à sa place. Tout est enregistré automatiquement.</p></div>
    <div class="d-flex align-items-center gap-3"><span class="small text-muted" data-save-state></span>@if($nextUrl)<a href="{{ $nextUrl }}" class="btn btn-primary">Document suivant →</a>@else<a href="{{ $finishUrl }}" class="btn btn-primary">{{ $finishLabel }}</a>@endif</div>
</div>
<div class="row g-4">
    <aside class="col-lg-3">
        <div class="band">Signataires</div>
        <div class="list-rows mb-3">@foreach($document->signatureRequests as $signature)<div><span class="num">{{ $loop->iteration }}</span><div class="small"><strong class="d-block">{{ $signature->signer_name }}</strong>Page {{ $signature->page }}</div></div>@endforeach</div>
        @unless($document->usesEid())
        <div class="band">Champs à compléter</div>
        <div class="panel p-3">
            <label class="form-label small mb-1" for="field-signer">Par</label>
            <select class="form-select form-select-sm mb-2" id="field-signer" data-field-signer>@foreach($document->signatureRequests->where('status', 'pending') as $signature)<option value="{{ $signature->id }}">{{ $signature->signer_name }}</option>@endforeach</select>
            <input class="form-control form-control-sm mb-2" data-field-label maxlength="80" placeholder="Libellé (facultatif)" aria-label="Libellé du champ">
            <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="field-required" data-field-required><label class="form-check-label small" for="field-required">Obligatoire</label></div>
            <div class="d-flex gap-1"><button type="button" class="btn btn-sm btn-outline-primary flex-fill" data-add-field="date">Date</button><button type="button" class="btn btn-sm btn-outline-primary flex-fill" data-add-field="checkbox">Coche</button><button type="button" class="btn btn-sm btn-outline-primary flex-fill" data-add-field="text">Texte</button></div>
        </div>
        @endunless
    </aside>
    <div class="col-lg-9"><div class="pdf-stage" data-pdf-stage data-pdf-url="{{ route('documents.file',$document) }}" data-save-url="{{ route('documents.placements.update',$document) }}" data-placements='@json($placements)' data-field-mode="{{ $document->usesEid() ? '' : 'design' }}" data-fields='@json($document->fields->map->toEditorArray())' data-field-create-url="{{ route('documents.fields.store', $document) }}" data-field-url-template="{{ route('documents.fields.update', [$document, '__ID__']) }}"></div></div>
</div>
@endsection
