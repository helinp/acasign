@extends('layouts.app')
@section('title', 'Mon profil')
@section('content')
<h1 class="page-title">Mon profil</h1>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="band"><span class="band__title"><x-icon name="user-circle" />Mes informations</span></div>
        <form method="post" action="{{ route('profile.update') }}" class="panel">@csrf @method('PUT')
            <div class="row g-3 mb-3">
                <div class="col-md-6"><label class="form-label" for="last_name">Nom</label><input class="form-control @error('last_name') is-invalid @enderror" id="last_name" name="last_name" maxlength="100" value="{{ old('last_name', $user->last_name) }}"></div>
                <div class="col-md-6"><label class="form-label" for="first_name">Prénom</label><input class="form-control @error('first_name') is-invalid @enderror" id="first_name" name="first_name" maxlength="100" value="{{ old('first_name', $user->first_name) }}"></div>
                <div class="col-12"><label class="form-label" for="job_title">Qualité</label><input class="form-control @error('job_title') is-invalid @enderror" id="job_title" name="job_title" maxlength="120" value="{{ old('job_title', $user->job_title) }}" placeholder="Directeur, professeur…"></div>
            </div>
            <fieldset class="mb-3">
                <legend class="form-label mb-1"><x-icon name="mail" class="me-1" />Notifications par e-mail <span class="text-muted small">({{ $user->email }})</span></legend>
                <div class="form-check"><input type="hidden" name="notify_to_sign" value="0"><input class="form-check-input" type="checkbox" name="notify_to_sign" value="1" id="notify_to_sign" @checked(old('notify_to_sign', $user->notify_to_sign ?? true))><label class="form-check-label" for="notify_to_sign">Un document m’est envoyé à signer</label></div>
                <div class="form-check"><input type="hidden" name="notify_signed" value="0"><input class="form-check-input" type="checkbox" name="notify_signed" value="1" id="notify_signed" @checked(old('notify_signed', $user->notify_signed ?? true))><label class="form-check-label" for="notify_signed">Un document que j’ai envoyé est signé</label></div>
                <p class="small text-muted mb-0 mt-1">Les refus, annulations et révocations sont toujours envoyés.</p>
            </fieldset>
            <button class="btn btn-primary"><x-icon name="device-floppy" />Enregistrer</button>
        </form>
    </div>
    <div class="col-lg-6">
        <div class="band"><span class="band__title"><x-icon name="signature" />Image de signature</span> @if($user->signature_path)<span><x-icon name="check" />Enregistrée</span>@endif</div>
        <div class="panel">
            @if($user->signature_path)<div class="signature-preview mb-3"><img src="{{ route('profile.signature.show', ['v' => $user->updated_at?->timestamp]) }}" alt="Votre image de signature"></div>@endif
            <form method="post" action="{{ route('profile.signature.store') }}" enctype="multipart/form-data">@csrf
                <label class="form-label" for="signature">{{ $user->signature_path ? 'Remplacer par' : 'Image' }} <span class="text-muted small">PNG ou JPEG, fond blanc, 5 Mo max.</span></label>
                <input class="form-control drop-zone mb-3" type="file" id="signature" name="signature" accept="image/png,image/jpeg" required>
                <button class="btn btn-primary"><x-icon name="upload" />{{ $user->signature_path ? 'Remplacer l’image' : 'Enregistrer l’image' }}</button>
            </form>
            @if($user->signature_path)<form method="post" action="{{ route('profile.signature.delete') }}" class="mt-3" data-confirm="Supprimer votre image de signature ? Vous ne pourrez plus signer sans eID tant qu’une nouvelle image n’est pas ajoutée.">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><x-icon name="trash" />Supprimer l’image</button></form>@endif
        </div>
    </div>
    <div class="col-12">@include('partials.eid-help', ['mode' => 'always', 'action' => 'test'])</div>
</div>
@endsection
