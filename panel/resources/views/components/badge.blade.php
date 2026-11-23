{{--
    Light badge with meaning (docs/DESIGN.md, "Farben mit Bedeutung").
    <x-badge tone="ok">Aktiv</x-badge>
    tone: ok (green), info (blue), warning (orange), danger (red), neutral (grey).
--}}
@props(['tone' => 'neutral'])
@php($color = ['ok' => 'green', 'info' => 'blue', 'warning' => 'orange', 'danger' => 'red', 'neutral' => 'secondary'][$tone] ?? 'secondary')
<span {{ $attributes->class(['badge', 'bg-'.$color.'-lt']) }}>{{ $slot }}</span>
