{{--
    System -> Statistics (E-148): visitors and page views of the websites,
    counted hourly on the servers from the access logs. Administrators only.
--}}
@php
    $number = fn (int $value) => \Illuminate\Support\Number::format($value, locale: app()->getLocale());
    // The change wins over the current choice (array_merge, not +, which keeps the left side).
    $link = fn (array $change) => route('statistics.index', array_filter(array_merge(['site' => $site?->id, 'range' => $range], $change), fn ($v) => $v !== null));
@endphp
<x-layouts.app :title="__('statistics.title')">
    <x-slot:sidebar>
        @include('system._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('statistics.title')" :pretitle="__('ui.nav.system')">
        <x-slot:actions>
            <div class="d-flex flex-wrap gap-2">
                <form method="GET" action="{{ route('statistics.index') }}" class="d-flex gap-2" data-ow-submit-on-change>
                    <input type="hidden" name="range" value="{{ $range }}">
                    <label class="visually-hidden" for="site">{{ __('statistics.site') }}</label>
                    <select id="site" name="site" class="form-select">
                        <option value="">{{ __('statistics.all_sites') }}</option>
                        @foreach ($table as $row)
                            <option value="{{ $row['site']->id }}" @selected($site?->id === $row['site']->id)>{{ $row['site']->display_domain }}</option>
                        @endforeach
                    </select>
                    <noscript><button type="submit" class="btn">{{ __('statistics.show') }}</button></noscript>
                </form>
                <div class="btn-group" role="group" aria-label="{{ __('statistics.range') }}">
                    @foreach (array_keys(\App\Http\Controllers\StatisticsController::RANGES) as $key)
                        <a href="{{ $link(['range' => $key]) }}" @class(['btn', 'btn-primary' => $range === (string) $key, 'btn-outline-primary' => $range !== (string) $key])
                            @if ($range === (string) $key) aria-current="page" @endif>{{ __('statistics.ranges.'.$key) }}</a>
                    @endforeach
                </div>
            </div>
        </x-slot:actions>
    </x-page-header>

    <div class="row row-cards align-items-start">
        <div class="col-lg-8">
            <x-card>
                <x-bar-chart :title="$site ? $site->display_domain : __('statistics.all_sites')" :subtitle="__('statistics.ranges.'.$range)"
                    :labels="$chart['labels']" :values="$chart['visitors']" :value-label="__('statistics.visitors')"
                    :extra="$chart['pageviews']" :extra-label="__('statistics.pageviews')" />
                <div class="d-flex flex-wrap gap-4 mt-3">
                    <div><div class="text-secondary small">{{ __('statistics.visitors') }}</div><div class="h2 mb-0">{{ $number(array_sum($chart['visitors'])) }}</div></div>
                    <div><div class="text-secondary small">{{ __('statistics.pageviews') }}</div><div class="h2 mb-0">{{ $number(array_sum($chart['pageviews'])) }}</div></div>
                </div>
                <x-slot:footer>
                    <span class="small text-secondary">
                        @if ($counted)
                            {{ __('statistics.counted', ['time' => \Illuminate\Support\Carbon::parse($counted)->setTimezone(config('owpanel.timezone'))->isoFormat('L LT')]) }}
                        @else
                            {{ __('statistics.not_counted') }}
                        @endif
                    </span>
                </x-slot:footer>
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card :title="__('statistics.about_title')" tone="info">
                <ul class="mb-0 ps-3">
                    @foreach (__('statistics.about') as $point)
                        <li>{{ $point }}</li>
                    @endforeach
                </ul>
            </x-card>
        </div>

        @if ($pages !== null)
            <div class="col-12">
                <x-card :title="__('statistics.pages_title')" :flush="true">
                    @if ($pages === [])
                        <div class="card-body text-secondary">{{ __('statistics.no_pages') }}</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-vcenter card-table">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('statistics.page') }}</th>
                                        <th scope="col" class="text-end">{{ __('statistics.pageviews') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($pages as [$path, $views])
                                        <tr>
                                            <td class="text-break"><code>{{ $path }}</code></td>
                                            <td class="text-end">{{ $number($views) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-card>
            </div>
        @endif

        <div class="col-12">
            <x-card :title="__('statistics.sites_title')" :flush="true">
                @if ($table->isEmpty())
                    <div class="card-body text-secondary">{{ __('statistics.no_sites') }}</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('sites.columns.domain') }}</th>
                                    <th scope="col">{{ __('sites.customer.label') }}</th>
                                    <th scope="col" class="text-end">{{ __('statistics.columns.today') }}</th>
                                    <th scope="col" class="text-end">{{ __('statistics.columns.week') }}</th>
                                    <th scope="col" class="text-end">{{ __('statistics.columns.month') }}</th>
                                    <th scope="col" class="text-end">{{ __('statistics.columns.pageviews') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($table as $row)
                                    <tr @class(['table-active' => $site?->id === $row['site']->id])>
                                        <td class="fw-medium text-break"><a href="{{ $link(['site' => $row['site']->id]) }}">{{ $row['site']->display_domain }}</a></td>
                                        <td>@include('sites._owner', ['site' => $row['site']])</td>
                                        <td class="text-end">{{ $number($row['today']) }}</td>
                                        <td class="text-end">{{ $number($row['week']) }}</td>
                                        <td class="text-end">{{ $number($row['month']) }}</td>
                                        <td class="text-end">{{ $number($row['pageviews']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>
    </div>
</x-layouts.app>
