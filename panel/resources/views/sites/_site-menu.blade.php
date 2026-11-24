{{-- Left menu of a website (E-137). Expects $site. --}}
<x-side-menu :pretitle="__('ui.nav.sites')" :title="$site->display_domain">
    <x-nav-link route="sites.show" :params="$site" icon="layout-dashboard">{{ __('sites.menu.overview') }}</x-nav-link>
    @if (Gate::any(['changeDomain', 'requestCertificate', 'assignCustomer'], $site))
        <x-nav-link route="sites.domain.show" :params="$site" icon="world">{{ __('sites.menu.domain') }}</x-nav-link>
    @endif
    @can('manageDns', $site)
        <x-nav-link route="sites.dns" :params="$site" icon="world-www" active="sites.dns*">{{ __('sites.menu.dns') }}</x-nav-link>
    @endcan
    <x-nav-link route="sites.certificate.show" :params="$site" icon="certificate">{{ __('sites.menu.certificate') }}</x-nav-link>
    @can('changePhp', $site)
        <x-nav-link route="sites.php.show" :params="$site" icon="brand-php">{{ __('sites.menu.php') }}</x-nav-link>
    @endcan
    @can('viewAny', [\App\Models\Database::class, $site])
        <x-nav-link route="databases.index" :params="$site" icon="database" active="databases.*">{{ __('sites.menu.databases') }}</x-nav-link>
    @endcan
    @can('manageWebserver', $site)
        <x-nav-link route="sites.webserver" :params="$site" icon="server-cog">{{ __('sites.menu.webserver') }}</x-nav-link>
    @endcan
    @can('manageCron', $site)
        <x-nav-link route="sites.cron" :params="$site" icon="clock">{{ __('sites.menu.cron') }}</x-nav-link>
    @endcan
    @can('manageSftp', $site)
        <x-nav-link route="sites.sftp" :params="$site" icon="folder-up" active="sites.sftp*">{{ __('sites.menu.sftp') }}</x-nav-link>
    @endcan
    @can('manageBackups', $site)
        <x-nav-link route="sites.backups" :params="$site" active="sites.backups*" icon="database-export">{{ __('sites.menu.backups') }}</x-nav-link>
    @endcan
    @can('viewTasks', $site)
        <x-nav-link route="sites.tasks" :params="$site" icon="list-check">{{ __('sites.menu.tasks') }}</x-nav-link>
    @endcan
    @can('viewLogs', $site)
        <x-nav-link route="sites.logs" :params="$site" icon="file-text">{{ __('sites.menu.logs') }}</x-nav-link>
    @endcan
    @can('suspend', $site)
        <x-nav-link route="sites.suspension" :params="$site" icon="lock">{{ __('sites.menu.suspension') }}</x-nav-link>
    @endcan
    @can('delete', $site)
        <x-nav-link route="sites.delete" :params="$site" icon="trash">{{ __('sites.menu.delete') }}</x-nav-link>
    @endcan
</x-side-menu>
