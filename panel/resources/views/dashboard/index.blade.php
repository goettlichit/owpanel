<x-layouts.app :title="__('dashboard.title')">
    <x-slot:sidebar>
        <x-side-menu :title="__('ui.nav.dashboard')">
            <x-nav-link route="dashboard" icon="home">{{ __('dashboard.menu.summary') }}</x-nav-link>
        </x-side-menu>
    </x-slot:sidebar>

    <x-page-header :title="__('dashboard.title')" :pretitle="__('dashboard.pretitle')" />

    <div class="row row-cards mb-3">
        <div class="col-12">
            <x-card :title="__('dashboard.welcome.title')">
                <p class="mb-0 text-secondary">{{ __('dashboard.welcome.text') }}</p>
            </x-card>
        </div>
    </div>

    @if ($stats)
        <div class="row row-cards mb-3">
            <div class="col-sm-6 col-xl-3">
                <x-stat-card :label="__('dashboard.stats.users')" :value="$stats['users']"
                    :hint="trans_choice('dashboard.stats.admins_hint', $stats['admins'], ['count' => $stats['admins']])"
                    :href="route('users.index')" />
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-stat-card :label="__('dashboard.stats.sites')" :value="$stats['sites']"
                    :hint="trans_choice('dashboard.stats.sites_hint', $stats['databases'], ['tls' => $stats['sites_tls'], 'count' => $stats['databases']])"
                    :href="route('sites.index')" />
            </div>
            <div class="col-sm-6 col-xl-3">
                @php($failedTone = $stats['lockouts'] > 0 ? 'danger' : ($stats['failed_logins'] > 0 ? 'warning' : 'ok'))
                <x-stat-card :label="__('dashboard.stats.failed_logins')" :value="$stats['failed_logins']"
                    :tone="$failedTone"
                    :badge="$failedTone === 'danger' ? __('dashboard.stats.check') : null"
                    :hint="$stats['lockouts'] > 0
                        ? trans_choice('dashboard.stats.lockouts_hint', $stats['lockouts'], ['count' => $stats['lockouts']])
                        : __('dashboard.stats.failed_logins_hint')"
                    :href="route('audit-log.index')" />
            </div>
            <div class="col-sm-6 col-xl-3">
                @php($missing = $stats['admins_without_2fa'])
                <x-stat-card :label="__('dashboard.stats.two_factor')" :value="$missing === 0 ? __('dashboard.stats.all_set') : $missing"
                    :tone="$missing === 0 ? 'ok' : 'danger'"
                    :badge="$missing === 0 ? null : __('dashboard.stats.action_needed')"
                    :hint="$missing === 0
                        ? __('dashboard.stats.two_factor_ok')
                        : trans_choice('dashboard.stats.two_factor_missing', $missing, ['count' => $missing])" />
            </div>
        </div>
    @endif

    <div class="row row-cards">
        @if ($servers !== null)
            {{-- The central hint on servers, updates and certificates (E-076). --}}
            @php($attention = $servers->filter(fn ($server) => $server->onlineState() !== 'online' || $server->updateState() !== 'current'))
            @php($good = $servers->isNotEmpty() && $attention->isEmpty() && $expiring->isEmpty())
            <div class="col-xl-6">
                <x-card :title="__('dashboard.servers.title')" :flush="true" class="h-100"
                    :accent="$servers->isEmpty() ? 'blue' : ($good ? 'green' : 'orange')">
                    <x-slot:actions>
                        <a href="{{ route('servers.index') }}" class="btn btn-sm btn-outline-primary">
                            <x-icon name="server" class="me-1" />{{ __('dashboard.servers.all') }}
                        </a>
                    </x-slot:actions>
                    <div class="card-body">
                        @if ($servers->isEmpty())
                            <p class="text-secondary mb-0">{{ __('dashboard.servers.none') }}</p>
                        @elseif ($good)
                            <p class="mb-0 text-success"><x-icon name="circle-check" class="me-1" />{{ __('dashboard.servers.all_good') }}</p>
                        @else
                            <p class="mb-0 text-warning"><x-icon name="alert-triangle" class="me-1" />{{ __('dashboard.servers.attention') }}</p>
                        @endif
                    </div>
                    @if ($servers->isNotEmpty())
                        <div class="list-group list-group-flush">
                            @foreach ($servers as $server)
                                <a href="{{ route('servers.updates', $server) }}" class="list-group-item list-group-item-action d-flex flex-wrap align-items-center gap-2">
                                    <span class="me-auto text-break">{{ $server->display_name }}</span>
                                    <x-server-state :server="$server" :detail="false" />
                                    <x-update-state :server="$server" />
                                </a>
                            @endforeach
                        </div>
                    @endif
                    @if ($expiring->isNotEmpty())
                        <div class="card-body border-top">
                            <div class="subheader mb-2">{{ __('dashboard.servers.certificates') }}</div>
                            <ul class="list-unstyled mb-0">
                                @foreach ($expiring as $certificate)
                                    @php($expired = $certificate['expires']->isPast())
                                    <li class="d-flex flex-wrap gap-2">
                                        <a href="{{ $certificate['href'] }}" class="me-auto text-break">{{ $certificate['name'] }}</a>
                                        <span @class(['badge', 'bg-red-lt' => $expired, 'bg-orange-lt' => ! $expired])>
                                            <x-icon name="certificate" class="me-1" />{{ __($expired ? 'dashboard.servers.expired' : 'dashboard.servers.expires', ['date' => $certificate['expires']->copy()->setTimezone(config('owpanel.timezone'))->isoFormat('L')]) }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </x-card>
            </div>
        @endif
    </div>
</x-layouts.app>
