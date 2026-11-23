{{--
    Entry in a side menu, marked as current when its route is active.
    "active" takes a route pattern, or a list of them, when more pages
    belong to the entry.
    <x-nav-link route="servers.show" :params="$server" icon="activity">…</x-nav-link>
--}}
@props(['route', 'icon', 'params' => [], 'active' => null])
@php($current = request()->routeIs(...(array) ($active ?? $route)))
<a @class(['nav-link', 'active' => $current]) href="{{ route($route, $params) }}" @if ($current) aria-current="page" @endif>
    <x-icon :name="$icon" />{{ $slot }}
</a>
