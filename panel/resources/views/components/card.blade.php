{{--
    Card with optional title, tone, actions and footer (docs/DESIGN.md, "Stil").
    Every card has a coloured line at the top (E-077); only cards that need
    care also get a tinted header.

    <x-card :title="…" tone="warning">
        …
        <x-slot:footer><button class="btn btn-warning">…</button></x-slot:footer>
    </x-card>

    tone:   null or info: blue line (data, information, harmless actions)
            success:      green line (set up, in order)
            warning:      orange line and header (security relevant change)
            danger:       red line and header (cannot be undone)
    accent: overrides the colour of the line for cards that show a state,
            e.g. accent="green" when everything is in order: blue, green,
            orange or red. The header follows tone only.
    Use :flush="true" for tables and lists that bring their own spacing.
--}}
@props(['title' => null, 'flush' => false, 'tone' => null, 'accent' => null])
@php
    $accent ??= match ($tone) {
        'success' => 'green',
        'warning' => 'orange',
        'danger' => 'red',
        default => 'blue',
    };
    $accent = in_array($accent, ['blue', 'green', 'orange', 'red'], true) ? $accent : 'blue';
    $tinted = in_array($tone, ['warning', 'danger'], true);
@endphp
<div {{ $attributes->class(['card']) }}>
    <div class="card-status-top bg-{{ $accent }}"></div>
    @if ($title || isset($actions))
        <div @class(['card-header', 'ow-card-header-'.$tone => $tinted])>
            @if ($title)
                <h2 class="card-title">{{ $title }}</h2>
            @endif
            @isset($actions)
                <div class="card-actions">{{ $actions }}</div>
            @endisset
        </div>
    @endif
    @if ($flush)
        {{ $slot }}
    @else
        <div class="card-body">{{ $slot }}</div>
    @endif
    @isset($footer)
        <div class="card-footer ow-card-footer">{{ $footer }}</div>
    @endisset
</div>
