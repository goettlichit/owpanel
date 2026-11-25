<x-layouts.app :title="__('servers.updates.title').' · '.$server->display_name">
    <x-slot:sidebar>
        @include('servers._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('servers.updates.title')" :pretitle="$server->display_name" />

    @php($updates = $server->updates ?? [])
    @php($packages = $updates['packages'] ?? [])
    @php($runs = $updates['runs'] ?? [])
    @php($at = fn (?int $ts) => $ts ? \Illuminate\Support\Carbon::createFromTimestamp($ts) : null)

    <x-update-notice :server="$server" />

    <div class="row row-cards">
        <div class="col-lg-6">
            <x-card :title="__('servers.updates.automatic.title')" class="h-100"
                :accent="$server->updates === null ? 'blue' : (($server->updates['automatic'] ?? false) ? 'green' : 'orange')">
                @if ($server->updates !== null)
                    <x-slot:actions>
                        @if ($updates['automatic'] ?? false)
                            <span class="badge bg-green-lt"><x-icon name="circle-check" class="me-1" />{{ __('servers.updates.automatic.enabled') }}</span>
                        @else
                            <span class="badge bg-red-lt"><x-icon name="circle-x" class="me-1" />{{ __('servers.updates.automatic.disabled') }}</span>
                        @endif
                    </x-slot:actions>
                    <p class="text-secondary">{{ __(($updates['automatic'] ?? false) ? 'servers.updates.automatic.enabled_text' : 'servers.updates.automatic.disabled_text') }}</p>
                @endif

                <dl class="row mb-0">
                    <dt class="col-sm-6">{{ __('servers.updates.automatic.lists_updated') }}</dt>
                    <dd class="col-sm-6"><x-time :value="$at($updates['lists_updated'] ?? null)" /></dd>

                    <dt class="col-sm-6">{{ __('servers.updates.automatic.upgraded') }}</dt>
                    <dd class="col-sm-6"><x-time :value="$at($updates['upgraded'] ?? null)" /></dd>

                    <dt class="col-sm-6">{{ __('servers.updates.automatic.checked') }}</dt>
                    <dd class="col-sm-6 mb-0">
                        <livewire:updates-checked :server="$server" />
                        @if ($server->updates_error)
                            @php($message = \Illuminate\Support\Facades\Lang::has('tasks.errors.'.$server->updates_error) ? __('tasks.errors.'.$server->updates_error) : $server->updates_error)
                            <div class="small text-danger">{{ __('servers.updates.automatic.check_error', ['error' => $message]) }}</div>
                        @endif
                    </dd>
                </dl>

                @can('checkUpdates', $server)
                    <x-slot:footer>
                        <form method="POST" action="{{ route('servers.updates.check', $server) }}" data-ow-busy="{{ __('servers.updates.refreshing') }}" data-ow-task="server-upgrade">
                            @csrf
                            <button type="submit" class="btn btn-outline-primary">
                                <x-icon name="refresh" class="me-1" />{{ __('servers.updates.automatic.check') }}
                            </button>
                        </form>
                    </x-slot:footer>
                @endcan
            </x-card>
        </div>

        @can('reboot', $server)
            <div class="col-lg-6">
                <form data-ow-busy="{{ __('tasks.busy.reboot') }}" data-ow-task="server-reboot" method="POST" action="{{ route('servers.reboot', $server) }}" class="h-100" novalidate>
                    @csrf
                    <x-card :title="__('servers.reboot.title')" tone="danger" class="h-100">
                        <p class="text-secondary">{{ __('servers.reboot.text') }}</p>
                        @if ($server->hostsPanel())
                            <p class="text-secondary">{{ __('servers.reboot.own_server') }}</p>
                        @endif
                        <label class="form-label required" for="confirm_name">{{ __('servers.reboot.confirm') }}</label>
                        <input type="text" id="confirm_name" name="confirm_name" required autocomplete="off"
                            autocapitalize="none" spellcheck="false" placeholder="{{ $server->name }}"
                            @disabled($rebootBusy)
                            @class(['form-control', 'is-invalid' => $errors->has('confirm_name')])>
                        @error('confirm_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        @if ($rebootBusy)
                            <div class="form-hint">{{ __('servers.reboot.busy') }}</div>
                        @endif
                        <x-slot:footer>
                            <button type="submit" class="btn btn-danger" @disabled($rebootBusy)>
                                <x-icon name="power" class="me-1" />{{ __('servers.reboot.submit') }}
                            </button>
                        </x-slot:footer>
                    </x-card>
                </form>
            </div>
        @endcan

        @if ($server->hostsPanel())
            {{-- The panel itself (E-154): installed and newest version, update by hand or at night (E-185). --}}
            @php($release = \App\Support\PanelRelease::latest())
            @php($newer = \App\Support\PanelRelease::updateAvailable())
            @php($updating = \App\Support\PanelUpdate::inProgress())
            @php($repository = \App\Support\PanelRelease::repository())
            <div class="col-12">
                <x-card :title="__('servers.updates.panel.title')" :accent="$newer ? 'orange' : 'blue'">
                    @if ($newer)
                        <x-slot:actions>
                            <span class="badge bg-orange-lt">{{ __('servers.updates.panel.available') }}</span>
                        </x-slot:actions>
                    @endif
                    <dl class="row">
                        <dt class="col-sm-4">{{ __('servers.updates.panel.installed') }}</dt>
                        <dd class="col-sm-8">
                            <code>{{ \App\Support\PanelRelease::installed() }}</code>
                            @if (\App\Support\PanelRelease::isTestBuild())
                                <span class="text-secondary small ms-1">{{ __('servers.updates.panel.test_build') }}</span>
                            @endif
                        </dd>
                        <dt class="col-sm-4">{{ __('servers.updates.panel.latest') }}</dt>
                        <dd class="col-sm-8">
                            @if ($release['version'])
                                <code>{{ $release['version'] }}</code>
                                @if ($repository)
                                    <a class="small ms-1" href="https://github.com/{{ $repository }}/releases/tag/v{{ $release['version'] }}" target="_blank" rel="noopener">{{ __('servers.updates.panel.changes') }}</a>
                                @endif
                            @else
                                <span class="text-secondary">–</span>
                            @endif
                            @if ($release['error'])
                                <div class="small text-secondary">{{ __('servers.updates.panel.errors.'.$release['error']) }}</div>
                            @endif
                        </dd>
                        <dt class="col-sm-4">{{ __('servers.updates.panel.checked') }}</dt>
                        <dd class="col-sm-8"><x-time :value="$release['checked_at'] ? \Illuminate\Support\Carbon::parse($release['checked_at']) : null" /></dd>
                        <dt class="col-sm-4">{{ __('servers.updates.panel.last_update') }}</dt>
                        <dd class="col-sm-8 mb-0"><livewire:panel-update-state :server="$server" /></dd>
                    </dl>

                    @can('upgrade', $server)
                        <hr class="my-3">
                        <form method="POST" action="{{ route('servers.updates.panel_auto', $server) }}" class="d-flex flex-wrap align-items-center gap-3">
                            @csrf
                            <label class="form-check form-switch mb-0">
                                <input type="hidden" name="enabled" value="0">
                                <input type="checkbox" class="form-check-input" name="enabled" value="1" @checked(\App\Support\PanelUpdate::autoEnabled()) @disabled(\App\Support\PanelRelease::isTestBuild())>
                                <span class="form-check-label">{{ __('servers.updates.panel.auto.label') }}</span>
                            </label>
                            <button type="submit" class="btn btn-outline-secondary btn-sm" @disabled(\App\Support\PanelRelease::isTestBuild())>{{ __('servers.updates.panel.auto.save') }}</button>
                            <div class="form-hint w-100 mt-0">{{ __(\App\Support\PanelRelease::isTestBuild() ? 'servers.updates.panel.auto.test_build' : 'servers.updates.panel.auto.hint') }}</div>
                        </form>
                    @endcan

                    <x-slot:footer>
                        <div class="d-flex flex-wrap gap-2 justify-content-end align-items-center">
                            <form method="POST" action="{{ route('servers.updates.release', $server) }}" data-ow-busy="{{ __('servers.updates.panel.checking') }}" class="me-auto">
                                @csrf
                                <button type="submit" class="btn btn-outline-primary"><x-icon name="refresh" class="me-1" />{{ __('servers.updates.automatic.check') }}</button>
                            </form>
                            @can('upgrade', $server)
                                @if ($newer && ! $updating)
                                    <form method="POST" action="{{ route('servers.updates.panel', $server) }}" class="d-flex flex-wrap align-items-center gap-2" data-ow-busy="{{ __('servers.updates.panel.starting') }}" data-ow-task="panel-update">
                                        @csrf
                                        <input type="hidden" name="version" value="{{ $release['version'] }}">
                                        <label class="form-check mb-0">
                                            <input type="checkbox" name="confirm" value="1" required @class(['form-check-input', 'is-invalid' => $errors->has('confirm')])>
                                            <span class="form-check-label">{{ __('servers.updates.panel.confirm', ['version' => $release['version']]) }}</span>
                                        </label>
                                        <button type="submit" class="btn btn-primary"><x-icon name="download" class="me-1" />{{ __('servers.updates.panel.update') }}</button>
                                    </form>
                                @else
                                    <button type="button" class="btn btn-primary" disabled><x-icon name="download" class="me-1" />{{ __('servers.updates.panel.update') }}</button>
                                @endif
                            @endcan
                        </div>
                    </x-slot:footer>
                </x-card>
            </div>
        @endif

        <div class="col-12">
            <x-card :title="__('servers.updates.pending_title')" :flush="true">
                @if ($packages === [])
                    <div class="card-body text-secondary">{{ __('servers.updates.pending_empty') }}</div>
                @else
                    <div class="card-body pb-0">
                        <p class="text-secondary small mb-3">{{ __('servers.updates.state.pending_hint') }}</p>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('servers.updates.columns.package') }}</th>
                                    <th scope="col">{{ __('servers.updates.columns.from') }}</th>
                                    <th scope="col">{{ __('servers.updates.columns.to') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($packages as $package)
                                    <tr>
                                        <td>
                                            <code class="text-break">{{ $package['name'] }}</code>
                                            @if ($package['security'])
                                                <span class="badge bg-red-lt ms-1"><x-icon name="shield-exclamation" class="me-1" />{{ __('servers.updates.security') }}</span>
                                            @endif
                                        </td>
                                        <td class="text-secondary text-break">{{ $package['from'] !== '' ? $package['from'] : __('servers.updates.new_package') }}</td>
                                        <td class="text-break">{{ $package['to'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if (($updates['pending'] ?? 0) > count($packages))
                        <div class="card-body small text-secondary">{{ __('servers.updates.pending_more', ['count' => $updates['pending'] - count($packages)]) }}</div>
                    @endif
                    @can('upgrade', $server)
                        {{-- Installs what this list shows (E-154). --}}
                        <x-slot:footer>
                            <form method="POST" action="{{ route('servers.updates.install', $server) }}" class="d-flex flex-wrap gap-2 justify-content-end align-items-center"
                                data-ow-busy="{{ __('servers.updates.installing') }}" data-ow-task="server-upgrade">
                                @csrf
                                <span class="text-secondary small me-auto">{{ __('servers.updates.install_hint') }}</span>
                                <button type="submit" class="btn btn-primary"><x-icon name="download" class="me-1" />{{ __('servers.updates.install', ['count' => $updates['pending'] ?? count($packages)]) }}</button>
                            </form>
                        </x-slot:footer>
                    @endcan
                @endif
            </x-card>
        </div>

        <div class="col-12">
            <x-card :title="__('servers.updates.history_title')" :flush="true">
                @if ($runs === [])
                    <div class="card-body text-secondary">{{ __('servers.updates.history_empty') }}</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('servers.updates.columns.time') }}</th>
                                    <th scope="col">{{ __('servers.updates.columns.kind') }}</th>
                                    <th scope="col">{{ __('servers.updates.columns.packages') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($runs as $run)
                                    <tr>
                                        <td class="text-nowrap"><x-time :value="$at($run['at'])" :stacked="true" /></td>
                                        <td>
                                            <span @class(['badge', 'bg-blue-lt' => $run['kind'] === 'automatic', 'bg-secondary-lt' => $run['kind'] !== 'automatic'])>
                                                {{ __('servers.updates.kinds.'.$run['kind']) }}
                                            </span>
                                        </td>
                                        <td>
                                            <div>{{ trans_choice('servers.updates.count', $run['count'], ['count' => $run['count']]) }}</div>
                                            <div class="small text-secondary text-break">{{ implode(', ', $run['packages']) }}@if ($run['count'] > count($run['packages'])) …@endif</div>
                                        </td>
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
