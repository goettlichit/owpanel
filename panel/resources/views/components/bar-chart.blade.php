{{--
    Bar chart drawn as SVG by the panel itself, like x-line-chart (E-075):
    one bar per day or month, hovering shows the label and both numbers.
    Used for the visitor statistics (E-148).

    <x-bar-chart :title="…" :labels="['16.11.', …]" :values="[12, …]"
        :value-label="__('statistics.visitors')" :extra="[30, …]" :extra-label="__('statistics.pageviews')" />

    values: the bars; extra: a second number shown in the tooltip only.
--}}
@props(['title', 'labels', 'values', 'valueLabel', 'extra' => [], 'extraLabel' => null, 'subtitle' => null])
@php
    $count = count($values);
    $highest = max([1, ...$values]);
    // A rounded top: 1, 2 or 5 times a power of ten.
    $magnitude = 10 ** floor(log10($highest));
    $scaleTop = collect([1, 2, 5, 10])->map(fn ($f) => $f * $magnitude)->first(fn ($v) => $v >= $highest);
    $width = $count > 0 ? 1000 / $count : 1000;
    $number = fn (int $value) => \Illuminate\Support\Number::format($value, locale: app()->getLocale());
    $tooltip = [
        'n' => $count,
        't' => array_values($labels),
        'v' => array_map($number, array_values($values)),
        'e' => array_map($number, array_values($extra)),
    ];
    $ticks = $count > 1 ? [0, intdiv($count - 1, 2), $count - 1] : ($count === 1 ? [0] : []);
@endphp
<div {{ $attributes->class(['ow-chart']) }}>
    <div class="d-flex flex-wrap align-items-baseline gap-2 mb-1">
        <h3 class="card-title mb-0">{{ $title }}</h3>
        @if ($subtitle)<span class="small text-secondary">{{ $subtitle }}</span>@endif
    </div>

    <div class="ow-chart-body" x-data="owChart" data-ow-chart="{{ json_encode($tooltip) }}" data-ow-chart-mode="bar">
        <div class="ow-chart-y" aria-hidden="true">
            @foreach ([1, 0.5, 0] as $fraction)
                <span style="top: {{ (1 - $fraction) * 100 }}%">{{ $number((int) round($fraction * $scaleTop)) }}</span>
            @endforeach
        </div>

        <div class="ow-chart-plot" @mouseleave="leave()" @mousemove="move($event)">
            <svg viewBox="0 0 1000 100" preserveAspectRatio="none" class="ow-chart-svg" role="img"
                aria-label="{{ $title }}: {{ $valueLabel }} {{ $number(array_sum($values)) }}">
                @foreach ([0, 50, 100] as $gy)
                    <line x1="0" x2="1000" y1="{{ $gy }}" y2="{{ $gy }}" class="ow-chart-grid" />
                @endforeach
                @foreach (array_values($values) as $index => $value)
                    @php($height = $value / $scaleTop * 100)
                    <rect x="{{ round($index * $width + $width * 0.15, 2) }}" width="{{ round($width * 0.7, 2) }}"
                        y="{{ round(100 - $height, 2) }}" height="{{ round($height, 2) }}" class="ow-chart-bar"
                        :class="barClass({{ $index }})" />
                @endforeach
            </svg>

            @if (array_sum($values) === 0)
                <div class="ow-chart-empty small text-secondary">{{ __('statistics.no_data') }}</div>
            @endif

            <div x-show="active" x-cloak class="ow-chart-tooltip" :style="tooltipStyle" :class="tooltipClass">
                <div class="fw-medium" x-text="label"></div>
                <div class="text-nowrap">{{ $valueLabel }} <span class="ms-1" x-text="value()"></span></div>
                @if ($extraLabel)
                    <div class="text-nowrap">{{ $extraLabel }} <span class="ms-1" x-text="extra"></span></div>
                @endif
            </div>
        </div>

        <div class="ow-chart-x" aria-hidden="true">
            @foreach ($ticks as $tick)
                <span>{{ $labels[$tick] }}</span>
            @endforeach
        </div>
    </div>
</div>
