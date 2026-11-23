{{-- Main area in the second header row. $active is a route pattern such as "audit-log.*", or a list of them. --}}
@props(['route', 'icon', 'active' => null])
@php($isActive = request()->routeIs(...(array) ($active ?? $route)))
<a href="{{ route($route) }}" @class(['ow-mainnav-link', 'active' => $isActive]) @if ($isActive) aria-current="page" @endif>
    <x-icon :name="$icon" />{{ $slot }}
</a>
