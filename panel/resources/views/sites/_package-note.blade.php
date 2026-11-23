{{--
    Which package limits apply on this page (E-125, E-139). Expects $site
    with customer.package and $kind ('cron' or 'databases'). Websites of the
    administration have no limits and no note.
--}}
@php($package = $site->customer?->package)
@php($unlimited = __('packages.unlimited'))
@if (\App\Support\Area::customer())
    {{-- The customer's own view of his package (E-144), "Sie" (E-132). --}}
    <div @class(['form-hint', 'text-warning' => $package === null])>
        @if ($package === null)
            {{ __('my.package.none') }}
        @elseif ($kind === 'cron')
            {{ __('my.package.cron', [
                'used' => $site->customer->cronJobs()->count(),
                'max' => $package->max_cron_jobs ?? $unlimited,
                'interval' => $package->min_cron_interval === null ? $unlimited : __('packages.units.minutes', ['count' => $package->min_cron_interval]),
            ]) }}
        @else
            {{ __('my.package.databases', ['used' => $site->customer->databases()->count(), 'max' => $package->max_databases ?? $unlimited]) }}
        @endif
    </div>
@elseif ($site->owner() === \App\Models\Site::OWNER_UNASSIGNED)
    <div class="form-hint text-warning">{{ __('packages.note.unassigned') }}
        <a href="{{ route('sites.domain.show', $site) }}">{{ __('packages.note.assign') }}</a></div>
@elseif ($site->owner() === \App\Models\Site::OWNER_CUSTOMER && $package === null)
    <div class="form-hint text-warning">{{ __('packages.note.no_package') }}
        <a href="{{ route('customers.package.show', $site->customer) }}">{{ __('packages.note.choose') }}</a></div>
@elseif ($package !== null)
    <div class="form-hint">
        @if ($kind === 'cron')
            {{ __('packages.note.cron', [
                'name' => $package->name,
                'used' => $site->customer->cronJobs()->count(),
                'max' => $package->max_cron_jobs ?? $unlimited,
                'interval' => $package->min_cron_interval === null ? $unlimited : __('packages.units.minutes', ['count' => $package->min_cron_interval]),
            ]) }}
        @else
            {{ __('packages.note.databases', [
                'name' => $package->name,
                'used' => $site->customer->databases()->count(),
                'max' => $package->max_databases ?? $unlimited,
            ]) }}
        @endif
    </div>
@endif
