@props(['type' => 'info'])
{{-- Message contextuel : l'icône indique la nature du message (succès, erreur, attention, information). --}}
@php($icon = ['success' => 'circle-check', 'danger' => 'alert-circle', 'warning' => 'alert-triangle'][$type] ?? 'info-circle')
<div {{ $attributes->class(['alert', 'alert-'.$type]) }}><x-icon :name="$icon" class="alert-icon" /><div>{{ $slot }}</div></div>
