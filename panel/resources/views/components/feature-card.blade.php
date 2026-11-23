{{--
    A feature that can be on or off, e.g. two-factor authentication: coloured
    top edge, title, short description and status on the right, then details
    and actions (docs/DESIGN.md, "Stil").

    <x-feature-card :title="…" :description="…" :active="true">
        …
    With :warning="…" an active feature that is only partly working, e.g. the
    www address without certificate: orange edge and that text as badge.
        <x-slot:actions>…</x-slot:actions>
    </x-feature-card>
--}}
@props(['title', 'description' => null, 'active' => false, 'warning' => null])
@php($warn = $active && $warning !== null)
<div {{ $attributes->class(['card', 'h-100']) }}>
    <div @class(['card-status-top', 'bg-orange' => $warn, 'bg-green' => $active && ! $warn, 'bg-blue' => ! $active])></div>
    <div class="card-body">
        <div class="d-flex align-items-start gap-3">
            <div class="me-auto">
                <h3 class="card-title mb-1">{{ $title }}</h3>
                @if ($description)
                    <p class="text-secondary mb-0">{{ $description }}</p>
                @endif
            </div>
            <x-badge :tone="$warn ? 'warning' : ($active ? 'ok' : 'neutral')" class="text-uppercase text-nowrap">{{ $warn ? $warning : ($active ? __('ui.state.active') : __('ui.state.off')) }}</x-badge>
        </div>
        @if (trim($slot) !== '')
            <hr class="my-3">
            <div class="text-secondary">{{ $slot }}</div>
        @endif
        @isset($actions)
            <div class="d-flex flex-wrap gap-2 mt-3">{{ $actions }}</div>
        @endisset
    </div>
</div>
