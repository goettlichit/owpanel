<x-layouts.app :title="__('servers.title')">
    <x-slot:sidebar>
        <x-side-menu :title="__('ui.nav.servers')">
            <x-nav-link route="servers.index" icon="server">{{ __('servers.all') }}</x-nav-link>
            @can('create', \App\Models\Server::class)
                <x-nav-link route="servers.join" icon="circle-plus">{{ __('servers.join.title') }}</x-nav-link>
            @endcan
        </x-side-menu>
    </x-slot:sidebar>

    <x-page-header :title="__('servers.title')" :pretitle="__('ui.brand')">
        @can('create', \App\Models\Server::class)
            <x-slot:actions>
                <a href="{{ route('servers.join') }}" class="btn btn-primary"><x-icon name="circle-plus" class="me-1" />{{ __('servers.join.title') }}</a>
            </x-slot:actions>
        @endcan
    </x-page-header>

    <x-card :flush="true">
        @if ($servers->isEmpty())
            <div class="card-body">
                <p class="text-secondary">{{ __('servers.empty') }} {{ __('servers.empty_hint') }}</p>
                <pre class="mb-0"><code>php artisan owpanel:add-server --name=… --host=127.0.0.1 --host-key-file=/etc/ssh/ssh_host_ed25519_key.pub</code></pre>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-vcenter card-table ow-server-table">
                    {{--
                        Each server is its own block: a grey label row with name,
                        addresses, state and host key, the values below, then a grey
                        label row from the addresses on with updates, snapshots and
                        backup, and their values. The name column stays white below the
                        first label row.
                    --}}
                    @foreach ($servers as $server)
                        <tbody class="ow-server-block">
                            <tr class="ow-server-labels">
                                <th scope="col">{{ __('servers.columns.name') }}</th>
                                <th scope="col">{{ __('servers.columns.public') }}</th>
                                <th scope="col">{{ __('servers.columns.state') }}</th>
                                <th scope="col">{{ __('servers.columns.host_key') }}</th>
                            </tr>
                            <tr>
                                <td rowspan="3" class="ow-server-name">
                                    <a href="{{ route('servers.show', $server) }}" class="text-nowrap">{{ $server->display_name }}</a>
                                    @if ($server->hostname && $server->hostname !== $server->name)
                                        <div class="small text-secondary">{{ $server->name }}</div>
                                    @endif
                                    @if ($server->booted_at && $server->onlineState() === 'online')
                                        <div class="small text-secondary">{{ __('servers.system.uptime', ['time' => $server->booted_at->locale(app()->getLocale())->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE)]) }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if ($server->public_ipv4 || $server->public_ipv6)
                                        @if ($server->public_ipv4)<div>{{ $server->public_ipv4 }}</div>@endif
                                        @if ($server->public_ipv6)<div class="small text-secondary">{{ $server->public_ipv6 }}</div>@endif
                                    @else
                                        {{ $server->address }}
                                    @endif
                                </td>
                                <td class="text-nowrap"><x-server-state :server="$server" /></td>
                                <td><code class="small text-break">{{ $server->host_key_fingerprint }}</code></td>
                            </tr>
                            <tr class="ow-server-labels">
                                <th scope="col">{{ __('servers.columns.updates') }}</th>
                                <th scope="col">{{ __('servers.columns.snapshots') }}</th>
                                <th scope="col">{{ __('servers.columns.backup') }}</th>
                            </tr>
                            <tr>
                                <td>
                                    <a href="{{ route('servers.updates', $server) }}" class="text-decoration-none"><x-update-state :server="$server" /></a>
                                </td>
                                <td>
                                    @php($snapshots = $hetznerConfigured && $server->snapshot_enabled)
                                    @can('manageSnapshots', $server)<a href="{{ route('servers.snapshots', $server) }}" class="text-decoration-none">@endcan
                                    <span @class(['badge', 'bg-green-lt' => $snapshots, 'bg-secondary-lt' => ! $snapshots])>
                                        <x-icon name="camera" class="me-1" />{{ $snapshots ? __('servers.protection.snapshots_on', ['time' => $server->snapshot_time]) : __('servers.protection.off') }}
                                    </span>
                                    @can('manageSnapshots', $server)</a>@endcan
                                </td>
                                <td class="text-nowrap">
                                    @php($backup = ! $server->backupConfigured() ? 'off' : ($server->backup_ready ? 'on' : 'not_connected'))
                                    @can('manageBackups', $server)<a href="{{ route('servers.backups', $server) }}" class="text-decoration-none">@endcan
                                    <span @class(['badge', 'bg-green-lt' => $backup === 'on', 'bg-orange-lt' => $backup === 'not_connected', 'bg-secondary-lt' => $backup === 'off'])>
                                        <x-icon name="database-export" class="me-1" />{{ $backup === 'on' && $server->backup_interval < 24 ? __('servers.protection.backup_interval', ['time' => $server->backup_time, 'hours' => $server->backup_interval]) : __('servers.protection.backup_'.$backup, ['time' => $server->backup_time]) }}
                                    </span>
                                    @can('manageBackups', $server)</a>@endcan
                                </td>
                            </tr>
                        </tbody>
                    @endforeach
                </table>
            </div>
        @endif
    </x-card>
</x-layouts.app>
