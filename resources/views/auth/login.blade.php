@extends('layouts.app')
@section('title', 'Connexion')
@section('main-class', '')
@section('content')
<div class="login"><div class="login__box">
    <div class="band">Signature de documents</div>
    <div class="login__body">
        <div class="brand mb-4">
            @if($logoUrl)<img src="{{ $logoUrl }}" alt="">@endif
            <span class="brand__name">{{ $entityName }}</span>
        </div>
        <a class="btn btn-primary btn-lg w-100" href="{{ route('auth.entra') }}"><x-icon name="login" />Se connecter avec Microsoft</a>
        @if (\App\Http\Controllers\Auth\DevLoginController::isEnabled())
            <div class="mt-4 pt-3 border-top">
                <p class="eyebrow">Développement uniquement</p>
                <form method="POST" action="{{ route('auth.dev') }}" class="d-flex gap-2">
                    @csrf
                    <button class="btn btn-sm btn-outline-primary flex-fill" name="account" value="admin"><x-icon name="settings" />Admin démo</button>
                    <button class="btn btn-sm btn-outline-primary flex-fill" name="account" value="client"><x-icon name="user-circle" />Client démo</button>
                </form>
            </div>
        @endif
    </div>
</div></div>
@endsection
