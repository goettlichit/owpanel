{{-- Left menu of a server. Expects $server. --}}
<x-side-menu :pretitle="__('ui.nav.servers')" :title="$server->display_name">
    <x-nav-link route="servers.show" :params="$server" icon="activity">{{ __('servers.menu.state') }}</x-nav-link>
    <x-nav-link route="servers.load" :params="$server" icon="chart-line">{{ __('servers.menu.load') }}</x-nav-link>
    <x-nav-link route="servers.updates" :params="$server" icon="package">{{ __('servers.menu.updates') }}</x-nav-link>
    @can('manageFirewall', $server)
        <x-nav-link route="servers.firewall" :params="$server" icon="wall" active="servers.firewall*">{{ __('servers.menu.firewall') }}</x-nav-link>
    @endcan
    @can('manageSsh', $server)
        <x-nav-link route="servers.ssh" :params="$server" icon="terminal-2">{{ __('servers.menu.ssh') }}</x-nav-link>
    @endcan
    @can('manageDns', $server)
        <x-nav-link route="servers.dns" :params="$server" icon="world-www">{{ __('servers.menu.dns') }}</x-nav-link>
    @endcan
    @can('manageSnapshots', $server)
        <x-nav-link route="servers.snapshots" :params="$server" icon="camera" active="servers.snapshots*">{{ __('servers.menu.snapshots') }}</x-nav-link>
    @endcan
    @can('manageBackups', $server)
        <x-nav-link route="servers.backups" :params="$server" icon="database-export">{{ __('servers.menu.backups') }}</x-nav-link>
    @endcan
    <x-nav-link route="servers.tasks" :params="$server" icon="list-check">{{ __('servers.menu.tasks') }}</x-nav-link>
    <x-nav-link route="servers.logs" :params="$server" icon="file-text">{{ __('servers.menu.logs') }}</x-nav-link>
</x-side-menu>
