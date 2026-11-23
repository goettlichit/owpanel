{{--
    Key figure with a coloured top edge (docs/DESIGN.md, "Farben mit Bedeutung"):
    info = blue, ok = green, warning = orange, danger = red with a tinted
    background. The meaning is always in the text as well, never in the
    colour alone.

    <x-stat-card :label="…" :value="3" :hint="…" tone="danger" :badge="__('dashboard.action_needed')" />
--}}
@props(['label', 'value', 'hint' => null, 'tone' => 'info', 'badge' => null, 'href' => null])
@php
    $color = ['info' => 'blue', 'ok' => 'green', 'warning' => 'orange', 'danger' => 'red'][$tone] ?? 'blue';
@endphp
<div {{ $attributes->class(['card', 'h-100', 'ow-stat', 'ow-stat-'.$tone]) }}>
    <div class="card-status-top bg-{{ $color }}"></div>
    <div class="card-body">
        <div class="d-flex align-items-start gap-2">
            <div @class(['subheader', 'me-auto', 'text-'.$color => $tone === 'danger'])>{{ $label }}</div>
            @if ($badge)
                <span class="badge bg-{{ $color }} text-{{ $color }}-fg">{{ $badge }}</span>
            @endif
        </div>
        <div @class(['ow-stat-value', 'text-'.$color => $tone === 'danger'])>{{ $value }}</div>
        @if ($hint)
            <div @class(['text-secondary' => $tone !== 'danger', 'text-'.$color => $tone === 'danger', 'fw-medium' => $tone === 'danger'])>
                @if ($href)
                    <a href="{{ $href }}" class="text-reset">{{ $hint }}</a>
                @else
                    {{ $hint }}
                @endif
            </div>
        @endif
    </div>
</div>
