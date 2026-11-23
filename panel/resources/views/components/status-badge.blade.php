{{--
    State of a task, website or server as colour, icon and text. Unknown states are
    shown as "unknown" instead of failing.
    <x-status-badge status="running" />
--}}
@props(['status'])
@php
    $styles = [
        'pending' => ['secondary', 'clock'],
        'running' => ['blue', 'loader-2'],
        'succeeded' => ['green', 'circle-check'],
        'failed' => ['red', 'circle-x'],
        'active' => ['green', 'circle-check'],
        'suspended' => ['orange', 'lock'],
        'deleting' => ['orange', 'trash'],
        'online' => ['green', 'plug-connected'],
        'offline' => ['red', 'plug-connected-x'],
    ];
    $key = array_key_exists($status, $styles) ? $status : 'unknown';
    [$color, $icon] = $styles[$key] ?? ['secondary', 'help-circle'];
@endphp
<span {{ $attributes->class(['badge', 'bg-'.$color.'-lt', 'status-'.$key]) }}>
    <x-icon :name="$icon" class="me-1" />{{ __('status.'.$key) }}
</span>
