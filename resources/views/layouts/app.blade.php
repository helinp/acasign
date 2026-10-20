<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Signatures') · {{ $shortName }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @if($themeCss)<style>{!! $themeCss !!}</style>@endif
</head>
<body>
<a class="skip-link" href="#contenu">Aller au contenu</a>
@auth
@php($me = auth()->user())
<div class="util"><div class="container d-flex justify-content-end align-items-center gap-3">
    <span class="d-none d-sm-inline">{{ $me->signatureName() }}</span>
    <a href="{{ route('profile.show') }}"><x-icon name="user-circle" />Mon profil</a>
    @if($me->isAdmin())<a href="{{ route('admin.users.index') }}"><x-icon name="settings" />Admin</a>@endif
    <form action="{{ route('logout') }}" method="post" class="m-0">@csrf<button class="util__btn"><x-icon name="logout" />Déconnexion</button></form>
</div></div>
<header class="head"><div class="container">
    <a class="brand" href="{{ route('dashboard') }}">
        @if($logoUrl)<img src="{{ $logoUrl }}" alt="">@endif
        <span><span class="brand__name" @if($shortName !== $entityName) title="{{ $entityName }}" @endif>{{ $shortName }}</span><span class="brand__sub">{{ $appSubtitle }}</span></span>
    </a>
    <button class="burger d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Menu"><span></span><span></span><span></span></button>
    <nav class="head-nav collapse d-lg-flex" id="mainNav" aria-label="Navigation principale">
        <a href="{{ route('dashboard') }}" @class(['is-on' => request()->routeIs('dashboard', 'signatures.*')]) @if(request()->routeIs('dashboard')) aria-current="page" @endif><x-icon name="home" />Accueil @if($toSignCount)<span class="count" title="Documents à signer">{{ $toSignCount }}<span class="visually-hidden"> à signer</span></span>@endif</a>
        <a href="{{ route('documents.index') }}" @class(['is-on' => request()->routeIs('documents.index', 'documents.show')]) @if(request()->routeIs('documents.index')) aria-current="page" @endif><x-icon name="files" />Documents</a>
        @if($me->can_send || $me->isAdmin())<a href="{{ route('documents.create') }}" class="btn btn-primary btn-sm"><x-icon name="file-plus" />Nouvelle demande</a>@endif
    </nav>
</div></header>
@endauth
<main id="contenu" tabindex="-1" class="@yield('main-class', 'container py-4 py-lg-5')">
    @if(session('success'))<x-alert type="success" role="status">{{ session('success') }}</x-alert>@endif
    @if($errors->any())
        {{-- Récapitulatif des erreurs : chaque message mène au champ concerné. --}}
        <x-alert type="danger" role="alert" tabindex="-1" data-error-summary>
            <strong class="d-block mb-1">{{ $errors->count() > 1 ? $errors->count().' points à corriger' : 'Un point à corriger' }}</strong>
            <ul class="mb-0 ps-3">@foreach($errors->messages() as $key => $messages)<li><a href="#{{ str_replace('.', '-', $key) }}">{{ $messages[0] }}</a></li>@endforeach</ul>
        </x-alert>
    @endif
    @yield('content')
</main>
@if($footerText || $contactEmail)
<footer class="foot"><div class="container">
    @if($footerText)<span>{{ $footerText }}</span>@endif
    @if($contactEmail)<a href="mailto:{{ $contactEmail }}"><x-icon name="mail" />Aide : {{ $contactEmail }}</a>@endif
</div></footer>
@endif
</body>
</html>
