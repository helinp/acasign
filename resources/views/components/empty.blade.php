@props(['icon' => 'inbox', 'title'])
{{-- État vide : icône, phrase claire et, si utile, l'action qui permet d'en sortir (slot). --}}
<div {{ $attributes->class(['empty', 'empty-sm']) }}>
    <div class="empty-icon"><x-icon :name="$icon" /></div>
    <p class="empty-title">{{ $title }}</p>
    @if($slot->isNotEmpty())<div class="empty-action">{{ $slot }}</div>@endif
</div>
