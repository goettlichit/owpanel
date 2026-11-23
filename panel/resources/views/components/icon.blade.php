{{-- Tabler icon from the local webfont. Decorative: the meaning is always in the text next to it. --}}
@props(['name'])
<i {{ $attributes->class(['ti', 'ti-'.$name]) }} aria-hidden="true"></i>
