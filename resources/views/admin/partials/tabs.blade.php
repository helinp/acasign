<nav class="tabs mb-4">
    <a class="@if(request()->routeIs('admin.users.*')) is-on @endif" href="{{ route('admin.users.index') }}">Utilisateurs</a>
    <a class="@if(request()->routeIs('admin.document-types.*')) is-on @endif" href="{{ route('admin.document-types.index') }}">Types de documents</a>
    <a class="@if(request()->routeIs('admin.settings.*')) is-on @endif" href="{{ route('admin.settings.edit') }}">Réglages</a>
</nav>
