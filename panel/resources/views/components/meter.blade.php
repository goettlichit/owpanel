{{--
    Usage as a bar whose colour carries the severity: blue up to 80 %,
    orange from 80 %, red from 90 % (E-075). The number is always written
    next to it, so the colour is never the only signal.

    <x-meter :label="__('servers.load.memory')" :percent="42.5" :detail="'3,4 GB / 7,8 GB'" />
--}}
@props(['label', 'percent', 'detail' => null])
@php
    $percent = max(0, min(100, (float) $percent));
    $tone = $percent >= 90 ? 'red' : ($percent >= 80 ? 'orange' : 'primary');
@endphp
<div {{ $attributes }}>
    <div class="d-flex align-items-baseline mb-1">
        <span class="subheader">{{ $label }}</span>
        <span class="ms-auto h3 mb-0">{{ \App\Support\Units::percent($percent) }}</span>
    </div>
    <div class="progress progress-sm" role="progressbar" aria-label="{{ $label }}" aria-valuenow="{{ round($percent) }}" aria-valuemin="0" aria-valuemax="100">
        <div class="progress-bar bg-{{ $tone }}" style="width: {{ $percent }}%"></div>
    </div>
    @if ($detail)
        <div class="small text-secondary mt-1">{{ $detail }}</div>
    @endif
</div>
