<x-layouts.app :title="$customer->display_name">
    <x-slot:sidebar>
        @include('customers._customer-menu')
    </x-slot:sidebar>

    <x-page-header :title="$customer->display_name" :pretitle="__('customers.title').' · '.$customer->display_number" />

    @php
        $package = $customer->package;
        $maxSites = $package?->max_sites;
        $accessState = match (true) {
            $access === null => 'none',
            $access->isLocked() => 'locked',
            $access->hasOpenInvitation() => 'invited',
            $access->invitation_token !== null => 'expired',
            $access->password_change_required => 'start_password',
            default => 'active',
        };
    @endphp

    <div class="row row-cards">
        {{-- Key figures; each leads to its page in the left menu. --}}
        <div class="col-sm-6 col-lg-4">
            <x-stat-card :label="__('customers.overview.sites')" :value="$usage['sites']"
                :tone="$maxSites !== null && $usage['sites'] > $maxSites ? 'warning' : 'info'"
                :hint="$package === null ? __('customers.overview.no_limit') : ($maxSites === null ? __('packages.unlimited') : __('customers.overview.of', ['max' => $maxSites]))" />
        </div>
        <div class="col-sm-6 col-lg-4">
            <x-stat-card :label="__('customers.overview.package')" :value="$package?->name ?? __('customers.package.none')"
                :tone="$package === null || $customer->exceededLimits($usage) !== [] ? 'warning' : 'ok'"
                :hint="$package === null ? __('customers.overview.no_package') : ($customer->exceededLimits($usage) !== [] ? __('customers.package.over') : __('customers.overview.within'))"
                :href="route('customers.package.show', $customer)" />
        </div>
        <div class="col-sm-6 col-lg-4">
            <x-stat-card :label="__('customers.overview.access')"
                :value="$accessState === 'none' ? __('customers.overview.no_access') : __('customers.access.states.'.$accessState)"
                :tone="['locked' => 'danger', 'expired' => 'warning', 'active' => 'ok'][$accessState] ?? 'info'"
                :hint="$access?->email ?? __('customers.overview.access_hint')"
                :href="route('customers.access', $customer)" />
        </div>

        <div class="col-12">
            <x-card :title="__('customers.sites.title')" :flush="true">
                <x-slot:actions>
                    @can('create', App\Models\Site::class)
                        <a href="{{ route('sites.create', ['customer' => $customer->id]) }}" class="btn btn-sm btn-outline-primary">
                            <x-icon name="plus" class="me-1" />{{ __('customers.sites.create') }}
                        </a>
                    @endcan
                </x-slot:actions>
                @if ($sites->isEmpty())
                    <div class="card-body text-secondary">{{ __('customers.sites.empty') }}</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('sites.columns.domain') }}</th>
                                    <th scope="col">{{ __('sites.columns.status') }}</th>
                                    <th scope="col">{{ __('sites.columns.tls') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($sites as $site)
                                    <tr>
                                        <td><a href="{{ route('sites.show', $site) }}" class="text-break">{{ $site->display_domain }}</a></td>
                                        <td><x-status-badge :status="$site->badgeStatus()" /></td>
                                        <td class="text-nowrap">@include('sites._tls', ['site' => $site])</td>
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
