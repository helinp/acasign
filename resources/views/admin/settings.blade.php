@extends('layouts.app')
@section('title', 'Réglages')
@section('content')
<h1 class="page-title">Administration</h1>
@include('admin.partials.tabs')
<form method="post" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data" style="max-width:760px" novalidate>@csrf @method('PUT')
    <div class="band"><span class="band__title"><x-icon name="id" />Identité de l’établissement</span></div>
    <div class="panel mb-4">
        <div class="mb-3">
            <label class="form-label" for="entity_name">Nom de l’établissement <span class="text-muted small">pages de connexion et de vérification, PDF signés</span></label>
            <input class="form-control @error('entity_name') is-invalid @enderror" id="entity_name" name="entity_name" maxlength="120" value="{{ old('entity_name', $values['entity_name']) }}" placeholder="{{ config('app.name') }}">
            <x-field-error name="entity_name" />
        </div>
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label" for="entity_short_name">Nom court <span class="text-muted small">en-tête, 30 car. max.</span></label>
                <input class="form-control @error('entity_short_name') is-invalid @enderror" id="entity_short_name" name="entity_short_name" maxlength="30" value="{{ old('entity_short_name', $values['entity_short_name']) }}" placeholder="Sigle ou nom d’usage">
                <x-field-error name="entity_short_name" />
            </div>
            <div class="col-md-6">
                <label class="form-label" for="app_subtitle">Sous-titre <span class="text-muted small">sous le nom</span></label>
                <input class="form-control @error('app_subtitle') is-invalid @enderror" id="app_subtitle" name="app_subtitle" maxlength="60" value="{{ old('app_subtitle', $values['app_subtitle']) }}" placeholder="{{ \App\Models\Setting::DEFAULT_SUBTITLE }}">
                <x-field-error name="app_subtitle" />
            </div>
        </div>
        <div>
            <label class="form-label" for="logo">Logo <span class="text-muted small">PNG, JPEG ou WebP · 1 Mo</span></label>
            @if($logoPath)<div class="d-flex align-items-center gap-3 mb-2"><img src="{{ route('branding.logo', ['v' => substr(md5($logoPath), 0, 8)]) }}" alt="Logo actuel" style="height:48px;width:auto"><label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="remove_logo" value="1"> <span class="small">Retirer</span></label></div>@endif
            <input class="form-control drop-zone @error('logo') is-invalid @enderror" type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp">
            <x-field-error name="logo" />
        </div>
    </div>

    <div class="band"><span class="band__title"><x-icon name="adjustments-horizontal" />Couleurs</span></div>
    <div class="panel mb-4">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="action_color">Liens et boutons</label>
                <div class="d-flex align-items-center gap-2"><input class="form-control form-control-color @error('action_color') is-invalid @enderror" type="color" id="action_color" name="action_color" value="{{ old('action_color', $actionColor) }}" data-color-preview="action"><code class="small" data-color-value="action_color">{{ old('action_color', $actionColor) }}</code></div>
                <div class="small text-muted mt-1">Réservée à tout ce qui se clique ; doit rester foncée (contraste ≥ 4,5:1 sur blanc).</div>
                <x-field-error name="action_color" />
            </div>
            <div class="col-md-6">
                <label class="form-label" for="brand_color">Nom de l’établissement</label>
                <div class="d-flex align-items-center gap-2"><input class="form-control form-control-color @error('brand_color') is-invalid @enderror" type="color" id="brand_color" name="brand_color" value="{{ old('brand_color', $brandColor) }}" data-color-preview="brand"><code class="small" data-color-value="brand_color">{{ old('brand_color', $brandColor) }}</code></div>
                <div class="small text-muted mt-1">Couleur de la marque dans l’en-tête (contraste ≥ 3:1).</div>
                <x-field-error name="brand_color" />
            </div>
        </div>
        <div class="theme-preview mt-3" data-theme-preview aria-hidden="true">
            <span class="theme-preview__brand">{{ $values['entity_short_name'] ?: ($values['entity_name'] ?: config('app.name')) }}</span>
            <span class="theme-preview__link">Un lien</span>
            <span class="theme-preview__btn"><x-icon name="signature" />Signer</span>
        </div>
        <label class="form-check mt-3 mb-0"><input class="form-check-input" type="checkbox" name="reset_colors" value="1"> <span class="small">Rétablir les couleurs par défaut</span></label>
    </div>

    <div class="band"><span class="band__title"><x-icon name="mail" />Contact et adresses</span></div>
    <div class="panel mb-4">
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label" for="email_domain">Domaine des adresses</label>
                <input class="form-control @error('email_domain') is-invalid @enderror" id="email_domain" name="email_domain" maxlength="120" value="{{ old('email_domain', $values['email_domain']) }}" placeholder="{{ $defaultEmailDomain }}">
                <div class="small text-muted mt-1">Sert d’exemple dans les champs (prenom.nom@…).</div>
                <x-field-error name="email_domain" />
            </div>
            <div class="col-md-6">
                <label class="form-label" for="contact_email">E-mail de contact</label>
                <input class="form-control @error('contact_email') is-invalid @enderror" type="email" id="contact_email" name="contact_email" maxlength="120" value="{{ old('contact_email', $values['contact_email']) }}" placeholder="secretariat@{{ $defaultEmailDomain }}">
                <div class="small text-muted mt-1">Affiché en pied de page pour l’aide.</div>
                <x-field-error name="contact_email" />
            </div>
        </div>
        <label class="form-label" for="footer_text">Pied de page <span class="text-muted small">mentions, protection des données · 300 car. max.</span></label>
        <textarea class="form-control @error('footer_text') is-invalid @enderror" id="footer_text" name="footer_text" maxlength="300" rows="2">{{ old('footer_text', $values['footer_text']) }}</textarea>
        <x-field-error name="footer_text" />
    </div>
    <button class="btn btn-primary"><x-icon name="device-floppy" />Enregistrer</button>
</form>
@endsection
