{{--
    Small line chart drawn as SVG by the panel itself, no chart library
    (E-075). Gaps (null) stay gaps. Hovering shows a crosshair with time and
    values; the line below the chart gives the same numbers as text.

    <x-line-chart :title="__('servers.load.cpu')" :times="$history['times']" :range="$range"
        :series="[['label' => 'CPU', 'values' => $history['cpu']]]" :max="100" format="percent" />

    series: up to two, each ['label' => string, 'values' => list<?float>]
    format: percent | load | rate
    max:    fixed top of the scale, or null for a rounded maximum of the data
    floor:  smallest top of the scale when max is null
--}}
@props(['title', 'times', 'series', 'range', 'format' => 'percent', 'max' => null, 'floor' => 1, 'subtitle' => null])
@php
    // Plot coordinates: x 0..1000, y 0..100. The SVG stretches to the card
    // (preserveAspectRatio="none"); strokes keep their width, and all text
    // is HTML, so nothing gets distorted.
    $count = count($times);

    $formatValue = fn (?float $value) => $value === null ? '–' : match ($format) {
        'rate' => \App\Support\Units::rate($value),
        'load' => \App\Support\Units::load($value),
        default => \App\Support\Units::percent($value),
    };

    // A rounded top of the scale: 1, 2 or 5 times a power of ten.
    $scaleTop = $max;
    if ($scaleTop === null) {
        $values = array_filter(array_merge(...array_column($series, 'values')), fn ($v) => $v !== null);
        $highest = max([$floor, ...array_values($values)]);
        // Byte rates round within their unit (KB, MB, …), so the axis reads 100 KB/s, not 97,7 KB/s.
        $unit = $format === 'rate' ? 1024 ** max(0, floor(log($highest, 1024))) : 1;
        $magnitude = 10 ** floor(log10($highest / $unit));
        $scaleTop = $unit * collect([1, 2, 5, 10])->map(fn ($f) => $f * $magnitude)->first(fn ($v) => $v * $unit >= $highest);
    }
    $scaleTop = max($scaleTop, 0.000001);

    $x = fn (int $i) => round($count > 1 ? $i / ($count - 1) * 1000 : 500, 2);
    $y = fn (float $value) => round(100 - min($value, $scaleTop) / $scaleTop * 100, 2);

    // Paths per series, broken where values are missing.
    $paths = [];
    foreach ($series as $s => $line) {
        $segments = [];
        $current = [];
        foreach ($line['values'] as $i => $value) {
            if ($value === null) {
                if ($current) { $segments[] = $current; }
                $current = [];
            } else {
                $current[] = [$x($i), $y($value)];
            }
        }
        if ($current) { $segments[] = $current; }

        $paths[$s] = array_map(function (array $points) {
            // A single point becomes a dot: a zero-length line with round caps.
            $line = count($points) === 1
                ? 'M'.$points[0][0].' '.$points[0][1].' h0.01'
                : 'M'.implode(' L', array_map(fn ($p) => $p[0].' '.$p[1], $points));
            $area = $line.' L'.end($points)[0].' 100 L'.$points[0][0].' 100 Z';

            return ['line' => $line, 'area' => $area];
        }, $segments);
    }

    $timezone = config('owpanel.timezone');
    $tickFormat = $range === '7d' ? 'dd, HH:mm' : 'HH:mm';
    $at = fn (int $ts) => \Illuminate\Support\Carbon::createFromTimestamp($ts)->setTimezone($timezone);
    $ticks = $count > 1 ? [0, intdiv($count - 1, 2), $count - 1] : [];

    // Text alternative: current, average and highest value of each series.
    $summary = array_map(function (array $line) use ($formatValue) {
        $values = array_values(array_filter($line['values'], fn ($v) => $v !== null));

        return [
            'label' => $line['label'],
            'current' => $formatValue($values ? end($values) : null),
            'average' => $formatValue($values ? array_sum($values) / count($values) : null),
            'highest' => $formatValue($values ? max($values) : null),
            'empty' => $values === [],
        ];
    }, $series);

    $tooltip = [
        'n' => $count,
        't' => array_map(fn ($ts) => $at($ts)->isoFormat($range === '7d' ? 'dd, L LT' : 'LT'), $times),
        'v' => array_map(fn ($line) => array_map($formatValue, $line['values']), $series),
    ];
    $hasData = ! collect($summary)->every('empty');
@endphp
<div {{ $attributes->class(['ow-chart']) }}>
    <div class="d-flex flex-wrap align-items-baseline gap-2 mb-1">
        <h3 class="card-title mb-0">{{ $title }}</h3>
        @if ($subtitle)<span class="small text-secondary">{{ $subtitle }}</span>@endif
        @if (count($series) > 1)
            {{-- Legend: two series always get one (identity never by colour alone). --}}
            <ul class="list-inline small text-secondary mb-0 ms-auto">
                @foreach ($series as $s => $line)
                    <li class="list-inline-item"><span class="ow-chart-key ow-chart-{{ $s + 1 }}"></span>{{ $line['label'] }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="ow-chart-body" x-data="owChart" data-ow-chart="{{ json_encode($tooltip) }}">
        {{-- y axis: 0, half, top --}}
        <div class="ow-chart-y" aria-hidden="true">
            @foreach ([1, 0.5, 0] as $fraction)
                <span style="top: {{ (1 - $fraction) * 100 }}%">{{ $formatValue($fraction * $scaleTop) }}</span>
            @endforeach
        </div>

        <div class="ow-chart-plot" @mouseleave="leave()" @mousemove="move($event)">
            <svg viewBox="0 0 1000 100" preserveAspectRatio="none" class="ow-chart-svg" role="img"
                aria-label="{{ $title }}: {{ collect($summary)->map(fn ($s) => $s['label'].' '.__('servers.load.current').' '.$s['current'].', '.__('servers.load.highest').' '.$s['highest'])->implode('; ') }}">
                @foreach ([0, 50, 100] as $gy)
                    <line x1="0" x2="1000" y1="{{ $gy }}" y2="{{ $gy }}" class="ow-chart-grid" />
                @endforeach

                @foreach ($paths as $s => $segments)
                    @foreach ($segments as $segment)
                        @if (count($series) === 1)
                            <path d="{{ $segment['area'] }}" class="ow-chart-area ow-chart-{{ $s + 1 }}" />
                        @endif
                        <path d="{{ $segment['line'] }}" class="ow-chart-line ow-chart-{{ $s + 1 }}" />
                    @endforeach
                @endforeach

                {{-- Crosshair, snapped to the nearest point. --}}
                <line x-show="active" x-cloak y1="0" y2="100" class="ow-chart-crosshair" :x1="crossX" :x2="crossX" />
            </svg>

            @unless ($hasData)
                <div class="ow-chart-empty small text-secondary">{{ __('servers.load.no_data') }}</div>
            @endunless

            <div x-show="active" x-cloak class="ow-chart-tooltip" :style="tooltipStyle" :class="tooltipClass">
                <div class="fw-medium" x-text="label"></div>
                @foreach ($series as $s => $line)
                    <div class="text-nowrap">
                        <span class="ow-chart-key ow-chart-{{ $s + 1 }}"></span>{{ $line['label'] }}
                        <span class="ms-1" x-text="value({{ $s }})"></span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="ow-chart-x" aria-hidden="true">
            @foreach ($ticks as $tick)
                <span>{{ $at($times[$tick])->isoFormat($tickFormat) }}</span>
            @endforeach
        </div>
    </div>

    <dl class="ow-chart-summary small text-secondary mb-0">
        @foreach ($summary as $s => $line)
            <div>
                @if (count($series) > 1)<dt class="d-inline"><span class="ow-chart-key ow-chart-{{ $s + 1 }}"></span>{{ $line['label'] }}:</dt>@endif
                <dd class="d-inline mb-0">
                    {{ __('servers.load.current') }} <span class="text-body">{{ $line['current'] }}</span> ·
                    {{ __('servers.load.average') }} {{ $line['average'] }} ·
                    {{ __('servers.load.highest') }} {{ $line['highest'] }}
                </dd>
            </div>
        @endforeach
    </dl>
</div>
