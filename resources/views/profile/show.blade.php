@extends('layouts.app')
@section('title', 'Mon profil')
@section('content')
<h1 class="page-title">Mon profil</h1>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="band">Texte de signature</div>
        <form method="post" action="{{ route('profile.update') }}" class="panel">@csrf @method('PUT')
            <div class="row g-3 mb-3">
                <div class="col-md-6"><label class="form-label" for="last_name">Nom</label><input class="form-control @error('last_name') is-invalid @enderror" id="last_name" name="last_name" maxlength="100" value="{{ old('last_name', $user->last_name) }}"></div>
                <div class="col-md-6"><label class="form-label" for="first_name">Prénom</label><input class="form-control @error('first_name') is-invalid @enderror" id="first_name" name="first_name" maxlength="100" value="{{ old('first_name', $user->first_name) }}"></div>
                <div class="col-12"><label class="form-label" for="job_title">Qualité</label><input class="form-control @error('job_title') is-invalid @enderror" id="job_title" name="job_title" maxlength="120" value="{{ old('job_title', $user->job_title) }}" placeholder="Directeur, professeur…"></div>
            </div>
            <p class="small text-muted mb-3">{{ $user->email }}</p>
            <button class="btn btn-primary">Enregistrer</button>
        </form>
    </div>
    <div class="col-lg-6">
        <div class="band">Image de signature @if($user->signature_path)<span>Enregistrée</span>@endif</div>
        <div class="panel">
            @if($user->signature_path)<div class="signature-preview mb-3"><img src="{{ route('profile.signature.show', ['v' => $user->updated_at?->timestamp]) }}" alt="Votre image de signature"></div>@endif
            <form method="post" action="{{ route('profile.signature.store') }}" enctype="multipart/form-data">@csrf
                <label class="form-label" for="signature">{{ $user->signature_path ? 'Remplacer par' : 'Image' }} <span class="text-muted small">PNG ou JPEG, fond blanc, 5 Mo max.</span></label>
                <input class="form-control mb-3" type="file" id="signature" name="signature" accept="image/png,image/jpeg" required>
                <button class="btn btn-primary">{{ $user->signature_path ? 'Remplacer' : 'Enregistrer' }}</button>
            </form>
            @if($user->signature_path)<form method="post" action="{{ route('profile.signature.delete') }}" class="mt-3">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Supprimer l’image</button></form>@endif
        </div>
    </div>
</div>
@endsection
