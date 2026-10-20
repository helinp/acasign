@props(['name', 'id' => null])
{{-- Message d'erreur sous le champ, relié par aria-describedby="<id>-error". --}}
@error($name)<div class="invalid-feedback d-block" id="{{ $id ?? str_replace('.', '-', $name) }}-error">{{ $message }}</div>@enderror
