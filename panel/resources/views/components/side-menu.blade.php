{{--
    Menu of the selected object on the left (E-057), e.g. a server or the
    system settings. Items are <x-nav-link>s.
    <x-side-menu :pretitle="__('ui.nav.system')" :title="…">…</x-side-menu>
--}}
@props(['title', 'pretitle' => null])
<aside class="ow-sidebar card" aria-label="{{ $title }}">
    <div class="card-body p-2">
        @if ($pretitle)
            <div class="text-uppercase text-secondary small fw-semibold px-2 pt-1">{{ $pretitle }}</div>
        @endif
        <div class="fw-semibold px-2 mb-2">{{ $title }}</div>
        <nav class="nav flex-column" aria-label="{{ $title }}">
            {{ $slot }}
        </nav>
    </div>
</aside>
