@extends('layouts.app')
@section('title', 'Action impossible')
@section('content')
<h1 class="page-title">Action impossible</h1>
<x-alert type="warning" role="alert">{{ $exception->getMessage() ?: 'Cette action n’est plus possible dans l’état actuel du document.' }}</x-alert>
<p class="small text-muted">Si vous venez de signer, la signature a peut-être déjà été enregistrée : vérifiez le document avant de recommencer.</p>
<a href="{{ route('dashboard') }}" class="btn btn-primary"><x-icon name="arrow-left" />Retour au tableau de bord</a>
@endsection
