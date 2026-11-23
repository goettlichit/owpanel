<x-layouts.app :title="$server->display_name">
    <x-slot:sidebar>
        @include('servers._menu')
    </x-slot:sidebar>

    <x-page-header :title="$server->display_name" :pretitle="__('ui.nav.servers')" />

    <x-update-notice :server="$server" :link="true" />

    <div class="row row-cards">
        <div class="col-lg-6">
            <x-card :title="__('servers.system.title')" class="h-100"
                :accent="match ($server->onlineState()) { 'online' => 'green', 'offline' => 'red', default => 'blue' }">
                <x-slot:actions>
                    <x-server-state :server="$server" :detail="false" />
                </x-slot:actions>

                <dl class="row mb-0">
                    <dt class="col-sm-5">{{ __('servers.system.hostname') }}</dt>
                    <dd class="col-sm-7 text-break">{{ $server->hostname ?? __('servers.system.unknown') }}</dd>

                    <dt class="col-sm-5">{{ __('servers.system.os') }}</dt>
                    <dd class="col-sm-7">{{ $server->os_name ?? '–' }}</dd>

                    <dt class="col-sm-5">{{ __('servers.system.kernel') }}</dt>
                    <dd class="col-sm-7">{{ $server->kernel ?? '–' }}</dd>

                    <dt class="col-sm-5">{{ __('servers.system.scripts') }}</dt>
                    <dd class="col-sm-7">
                        {{ $server->scripts_version ?? '–' }}
                        @if ($server->scripts_version !== null && $server->scripts_version !== \App\Support\PanelRelease::installed())
                            <div class="small text-warning">{{ __('servers.system.scripts_differ', ['version' => \App\Support\PanelRelease::installed()]) }}</div>
                        @endif
                    </dd>

                    {{-- PHP versions for websites (E-172). --}}
                    <dt class="col-sm-5">{{ __('servers.system.php') }}</dt>
                    <dd class="col-sm-7">{{ is_array($server->php_versions) && $server->php_versions !== [] ? implode(', ', $server->php_versions) : '–' }}</dd>

                    <dt class="col-sm-5">{{ __('servers.system.release_key') }}</dt>
                    <dd class="col-sm-7">
                        @php($keyMatch = \App\Support\ReleaseKeys::matches($server->release_keys))
                        @forelse ($server->release_keys ?? [] as $fingerprint)
                            <div class="text-break">
                                @if ($keyMatch === true)
                                    <x-icon name="circle-check" class="me-1 text-green" />
                                @elseif ($keyMatch === false)
                                    <x-icon name="circle-x" class="me-1 text-red" />
                                @endif
                                <code>{{ $fingerprint }}</code>
                            </div>
                        @empty
                            {{ $server->release_keys === null ? '–' : __('servers.system.release_key_none') }}
                        @endforelse
                        @if ($keyMatch === true)
                            <div class="small text-green">{{ __('servers.system.release_key_ok') }}</div>
                        @elseif ($keyMatch === false)
                            <div class="small text-red">{{ __('servers.system.release_key_differs') }}</div>
                        @endif
                    </dd>

                    <dt class="col-sm-5">{{ __('servers.system.cores') }}</dt>
                    <dd class="col-sm-7">{{ $server->cpu_cores ?? '–' }}</dd>

                    <dt class="col-sm-5">{{ __('servers.system.booted') }}</dt>
                    <dd class="col-sm-7">
                        @if ($server->booted_at)
                            <x-time :value="$server->booted_at" />
                            <div class="small text-secondary">{{ __('servers.system.uptime', ['time' => $server->booted_at->locale(app()->getLocale())->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE)]) }}</div>
                        @else
                            –
                        @endif
                    </dd>

                    @if ($server->services)
                        <dt class="col-sm-5">{{ __('servers.system.services') }}</dt>
                        <dd class="col-sm-7">
                            @foreach ($server->services as $name => $active)
                                <span class="badge {{ $active ? 'bg-green-lt' : 'bg-red-lt' }} me-1 mb-1" title="{{ $active ? __('servers.system.service_running') : __('servers.system.service_stopped') }}">
                                    <x-icon :name="$active ? 'circle-check' : 'circle-x'" class="me-1" />{{ $name }}
                                </span>
                            @endforeach
                        </dd>
                    @endif

                    <dt class="col-sm-5">{{ __('servers.system.last_seen') }}</dt>
                    <dd class="col-sm-7 mb-0">
                        <x-time :value="$server->last_seen_at" />
                        @if ($server->check_error)
                            <div class="small text-danger">{{ $server->checkErrorMessage() }}</div>
                        @endif
                    </dd>
                </dl>

                <x-slot:footer>
                    <a href="{{ route('servers.load', $server) }}" class="btn btn-outline-primary">
                        <x-icon name="chart-line" class="me-1" />{{ __('servers.system.show_load') }}
                    </a>
                </x-slot:footer>
            </x-card>
        </div>

        <div class="col-lg-6">
            <x-card :title="__('servers.connection.title')" class="h-100">
                <dl class="row mb-0">
                    <dt class="col-sm-5">{{ __('servers.connection.name') }}</dt>
                    <dd class="col-sm-7">{{ $server->name }}</dd>

                    <dt class="col-sm-5">{{ __('servers.connection.ipv4') }}</dt>
                    <dd class="col-sm-7">{{ $server->public_ipv4 ?? '–' }}</dd>

                    <dt class="col-sm-5">{{ __('servers.connection.ipv6') }}</dt>
                    <dd class="col-sm-7">{{ $server->public_ipv6 ?? '–' }}</dd>

                    <dt class="col-sm-5">{{ __('servers.connection.address') }}</dt>
                    <dd class="col-sm-7">{{ $server->address }}</dd>

                    <dt class="col-sm-5">{{ __('servers.connection.user') }}</dt>
                    <dd class="col-sm-7"><code>{{ config('owpanel.ssh.user') }}</code></dd>

                    <dt class="col-sm-5">{{ __('servers.connection.host_key') }}</dt>
                    <dd class="col-sm-7 mb-0">
                        <code class="small">{{ $server->host_key_fingerprint }}</code>
                        <div class="small text-secondary mt-1">{{ __('servers.connection.host_key_hint') }}</div>
                    </dd>
                </dl>
            </x-card>
        </div>

        @can('runTest', $server)
            <div class="col-lg-6">
                <form data-ow-busy="{{ __('tasks.busy.test') }}" data-ow-task="panel-test" method="POST" action="{{ route('servers.test', $server) }}" class="h-100">
                    @csrf
                    <x-card :title="__('servers.test.title')" tone="info" class="h-100">
                        <p class="text-secondary">{{ __('servers.test.text') }}</p>
                        <label class="form-check mb-0">
                            <input type="hidden" name="fail" value="0">
                            <input type="checkbox" class="form-check-input" name="fail" value="1">
                            <span class="form-check-label">{{ __('servers.test.fail') }}</span>
                        </label>
                        <x-slot:footer>
                            <button type="submit" class="btn btn-primary">
                                <x-icon name="player-play" class="me-1" />{{ __('servers.test.submit') }}
                            </button>
                        </x-slot:footer>
                    </x-card>
                </form>
            </div>
        @endcan

    </div>
</x-layouts.app>
