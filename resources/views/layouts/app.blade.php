<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Signatures') · {{ $entityName }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
@auth
@php($me = auth()->user())
<div class="util"><div class="container d-flex justify-content-end align-items-center gap-3">
    <span class="d-none d-sm-inline">{{ $me->signatureName() }}</span>
    <a href="{{ route('profile.show') }}">Mon profil</a>
    @if($me->isAdmin())<a href="{{ route('admin.users.index') }}">Admin</a>@endif
    <form action="{{ route('logout') }}" method="post" class="m-0">@csrf<button class="util__btn">Déconnexion</button></form>
</div></div>
<header class="head"><div class="container">
    <a class="brand" href="{{ route('dashboard') }}">
        @if($logoUrl)<img src="{{ $logoUrl }}" alt="">@endif
        <span><span class="brand__name">{{ $entityName }}</span><span class="brand__sub">Signature de documents</span></span>
    </a>
    <button class="burger d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Menu"><span></span><span></span><span></span></button>
    <nav class="head-nav collapse d-lg-flex" id="mainNav" aria-label="Navigation principale">
        <a href="{{ route('dashboard') }}" @class(['is-on' => request()->routeIs('dashboard', 'signatures.*')])>Accueil @if($toSignCount)<span class="count" title="Documents à signer">{{ $toSignCount }}</span>@endif</a>
        <a href="{{ route('documents.index') }}" @class(['is-on' => request()->routeIs('documents.index', 'documents.show')])>Documents</a>
        @if($me->can_send || $me->isAdmin())<a href="{{ route('documents.create') }}" class="btn btn-primary btn-sm">+ Nouvelle demande</a>@endif
    </nav>
</div></header>
@endauth
<main class="@yield('main-class', 'container py-4 py-lg-5')">
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0 ps-3">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @yield('content')
</main>
</body>
</html>
