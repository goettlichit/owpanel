{{-- Customer area: the customer's own websites (E-124). Texts address the customer with "Sie" (E-132). --}}
<x-layouts.app :title="__('my.sites.title')">
    <x-page-header :title="__('my.sites.title')" :pretitle="$package?->name" />

    @if ($package !== null && $sites->isNotEmpty())
        {{-- Storage of all websites against the package (E-126); info box top right. --}}
        <div class="row row-cards align-items-start mb-3">
            <div class="col-lg-8">
                <x-card :title="__('my.storage.title')" :accent="['ok' => 'blue', 'warning' => 'orange', 'full' => 'red'][$storage['state']]">
                    @if ($storage['state'] !== 'ok')
                        <div @class(['alert mb-3', 'alert-warning' => $storage['state'] === 'warning', 'alert-danger' => $storage['state'] === 'full']) role="status">
                            {{ __('my.storage.'.$storage['state']) }}
                        </div>
                    @endif
                    <x-storage-bar :used="$storage['used']" :limit="$storage['limit']" :measured="$measured"
                        :files="$storage['used'] === null ? null : (int) $sites->sum('usage_files_bytes')"
                        :databases="(int) $sites->sum('usage_db_bytes')" />
                </x-card>
            </div>
            <div class="col-lg-4">
                <x-card :title="__('my.storage.title')" tone="info">
                    <ul class="mb-0 ps-3">
                        @foreach (__('my.storage.about') as $point)
                            <li>{{ $point }}</li>
                        @endforeach
                    </ul>
                </x-card>
            </div>
        </div>
    @endif

    <x-card :flush="true">
        @if ($sites->isEmpty())
            <div class="card-body">
                <p class="fw-medium mb-1">{{ __('my.sites.empty') }}</p>
                <p class="text-secondary mb-0">{{ __('my.sites.empty_hint') }}</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('sites.columns.domain') }}</th>
                            <th scope="col">{{ __('sites.columns.status') }}</th>
                            <th scope="col">{{ __('sites.columns.tls') }}</th>
                            <th scope="col" class="text-end">{{ __('storage.column') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sites as $site)
                            <tr>
                                <td class="fw-medium text-break"><a href="{{ route('my.sites.show', $site) }}">{{ $site->display_domain }}</a></td>
                                <td><x-status-badge :status="$site->badgeStatus()" /></td>
                                <td class="text-nowrap">@include('sites._tls', ['site' => $site])</td>
                                <td class="text-nowrap text-end">{{ $site->usageBytes() === null ? '–' : App\Support\Units::bytes($site->usageBytes()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
</x-layouts.app>
