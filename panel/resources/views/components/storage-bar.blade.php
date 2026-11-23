{{--
    Storage against a limit (E-126): a bar with used, limit and free, from
    90 % orange, from 100 % red. Without limit only the used space.

    <x-storage-bar :used="$bytes" :limit="$bytes" :measured="$time" :files="…" :databases="…" />
--}}
@props(['used', 'limit' => null, 'measured' => null, 'files' => null, 'databases' => null])
@php
    $summary = \App\Customers\StorageQuota::summary($used, $limit);
    $bytes = fn (?int $value) => \App\Support\Units::bytes((int) $value);
    $color = ['ok' => 'bg-green', 'warning' => 'bg-orange', 'full' => 'bg-red'][$summary['state']];
@endphp
<div {{ $attributes }}>
    @if ($used === null)
        <p class="text-secondary mb-0">{{ __('storage.not_measured') }}</p>
    @else
        <div class="d-flex flex-wrap justify-content-between gap-2 mb-1">
            <span class="fw-medium">
                {{ $limit === null ? __('storage.used_unlimited', ['used' => $bytes($used)]) : __('storage.used_of', ['used' => $bytes($used), 'limit' => $bytes($limit)]) }}
            </span>
            @if ($limit !== null)
                <span @class(['text-secondary' => $summary['state'] === 'ok', 'text-orange' => $summary['state'] === 'warning', 'text-red' => $summary['state'] === 'full'])>
                    {{ __('storage.free', ['free' => $bytes($summary['free'])]) }}
                </span>
            @endif
        </div>
        @if ($limit !== null)
            <div class="progress progress-sm" role="progressbar" aria-label="{{ __('storage.title') }}"
                aria-valuenow="{{ (int) min(100, round($summary['percent'])) }}" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar {{ $color }}" style="width: {{ min(100, round($summary['percent'], 1)) }}%"></div>
            </div>
        @endif
        <div class="small text-secondary mt-1">
            @if ($files !== null)
                {{ __('storage.split', ['files' => $bytes($files), 'databases' => $bytes($databases)]) }} ·
            @endif
            @if ($measured)
                @php($local = \Illuminate\Support\Carbon::parse($measured)->setTimezone(config('owpanel.timezone')))
                {{ __('storage.measured', ['time' => $local->isToday() ? $local->isoFormat('LT') : $local->isoFormat('L LT')]) }}
            @endif
        </div>
    @endif
</div>
