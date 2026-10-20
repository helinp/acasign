<nav class="tabs mb-4" aria-label="Administration">
    @foreach(['admin.users' => ['Utilisateurs', 'users', 'admin.users.index'], 'admin.document-types' => ['Types de documents', 'tags', 'admin.document-types.index'], 'admin.settings' => ['Réglages', 'settings', 'admin.settings.edit']] as $prefix => [$label, $icon, $route])
        <a @class(['is-on' => request()->routeIs($prefix.'.*')]) href="{{ route($route) }}" @if(request()->routeIs($prefix.'.*')) aria-current="page" @endif><x-icon :name="$icon" />{{ $label }}</a>
    @endforeach
</nav>
