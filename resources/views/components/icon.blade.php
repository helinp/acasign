@props(['name', 'label' => null])
{{-- Icône Tabler (resources/icons/tabler, licence MIT) : décorative par défaut ; « label » la rend lisible par les lecteurs d'écran. --}}
@php
    $svg = file_get_contents(resource_path('icons/tabler/'.basename($name).'.svg'));
    $a11y = $label ? 'role="img" aria-label="'.e($label).'"' : 'aria-hidden="true" focusable="false"';
    $svg = preg_replace('/class="[^"]*"/', 'class="'.e(trim('icon '.$attributes->get('class'))).'" '.$a11y, $svg, 1);
@endphp
{!! preg_replace('/\s*\n\s*/', ' ', trim($svg)) !!}
