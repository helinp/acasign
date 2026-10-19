@extends('layouts.app')
@section('title', 'Utilisateurs')
@section('content')
<h1 class="page-title">Administration</h1>
@include('admin.partials.tabs')
<div class="band">Utilisateurs <span>{{ $users->total() }}</span></div>
<div class="border border-top-0"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Utilisateur</th><th>Dernière connexion</th><th>Rôle et envoi</th><th></th></tr></thead><tbody>@foreach($users as $user)@php($formId='user-'.$user->id)<tr><td><strong>{{ $user->name }}</strong><div class="small text-muted">{{ $user->email }}</div><form id="{{ $formId }}" method="post" action="{{ route('admin.users.update',$user) }}">@csrf @method('PUT')</form></td><td>{{ $user->last_login_at?->format('d/m/Y H:i') ?: 'Jamais' }}</td><td><div class="d-flex gap-2 align-items-center"><select class="form-select form-select-sm" name="role" form="{{ $formId }}" style="width:auto"><option value="user" @selected($user->role==='user')>Utilisateur</option><option value="admin" @selected($user->role==='admin')>Admin</option></select><label class="form-check"><input class="form-check-input" type="checkbox" name="can_send" value="1" form="{{ $formId }}" @checked($user->can_send)> <span class="small">Peut envoyer</span></label><label class="form-check"><input class="form-check-input" type="checkbox" name="disabled" value="1" form="{{ $formId }}" @checked($user->disabled_at)> <span class="small">Désactivé</span></label></div></td><td><button class="btn btn-sm btn-outline-primary" form="{{ $formId }}">Enregistrer</button></td></tr>@endforeach</tbody></table></div></div>
<div class="mt-3">{{ $users->links() }}</div>
@endsection
