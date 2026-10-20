@props(['status'])
{{-- Étiquette d'état : couleur + icône + texte (jamais la couleur seule). --}}
@php
    [$label, $icon] = match ($status) {
        'pending' => ['En attente', 'clock'],
        'signed' => ['Signé', 'circle-check'],
        'rejected' => ['Refusé', 'thumb-down'],
        'cancelled' => ['Annulé', 'circle-x'],
        'expired' => ['Expiré', 'hourglass-off'],
        'revoked' => ['Révoqué', 'ban'],
        'delegated' => ['Délégué', 'user-share'],
        default => [$status, 'info-circle'],
    };
@endphp
<span {{ $attributes->class(['status-badge', 'status-'.$status]) }}><x-icon :name="$icon" />{{ $label }}</span>
