{{-- One limit of a package as text: "5,0 GB", "5 Min.", "3" or "unbegrenzt". Expects $package and $limit. --}}
@php($value = $package->{$limit})
@if ($value === null)
    <span class="text-secondary">{{ __('packages.unlimited') }}</span>
@elseif ($limit === 'storage_mb')
    {{ \App\Support\Units::bytes($value * 1024 * 1024) }}
@elseif ($limit === 'min_cron_interval')
    {{ __('packages.units.minutes', ['count' => $value]) }}
@elseif ($limit === 'php_memory_mb' || $limit === 'php_upload_mb')
    {{ __('packages.units.megabytes', ['count' => $value]) }}
@elseif ($limit === 'php_time_s')
    {{ __('packages.units.seconds', ['count' => $value]) }}
@else
    {{ $value }}
@endif
