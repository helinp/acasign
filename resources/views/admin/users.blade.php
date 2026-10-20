@extends('layouts.app')
@section('title', 'Utilisateurs')
@section('content')
<h1 class="page-title">Administration</h1>
@include('admin.partials.tabs')
<div class="band">Utilisateurs <span>{{ $users->total() }}</span></div>
<div class="border border-top-0"><table class="table align-middle table-stack">
    <thead><tr><th scope="col">Utilisateur</th><th scope="col">Dernière connexion</th><th scope="col">Rôle et droits</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    @foreach($users as $user)
        @php($formId = 'user-'.$user->id)
        <tr>
            <td data-label="Utilisateur"><strong>{{ $user->name }}</strong><div class="small text-muted">{{ $user->email }}</div><form id="{{ $formId }}" method="post" action="{{ route('admin.users.update',$user) }}">@csrf @method('PUT')</form></td>
            <td data-label="Dernière connexion">{{ $user->last_login_at?->format('d/m/Y H:i') ?: 'Jamais' }}</td>
            <td data-label="Rôle et droits">
                <div class="d-flex flex-wrap gap-3 align-items-center">
                    <select class="form-select form-select-sm" name="role" form="{{ $formId }}" style="width:auto" aria-label="Rôle de {{ $user->name }}"><option value="user" @selected($user->role==='user')>Utilisateur</option><option value="admin" @selected($user->role==='admin')>Admin</option></select>
                    <label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="can_send" value="1" form="{{ $formId }}" @checked($user->can_send)> <span>Peut envoyer</span></label>
                    <label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="disabled" value="1" form="{{ $formId }}" @checked($user->disabled_at)> <span>Désactivé</span></label>
                </div>
            </td>
            <td class="text-end"><button class="btn btn-sm btn-outline-primary" form="{{ $formId }}">Enregistrer<span class="visually-hidden"> {{ $user->name }}</span></button></td>
        </tr>
    @endforeach
    </tbody>
</table></div>
<div class="mt-3">{{ $users->links() }}</div>
@endsection
