{{-- Left menu of a website in the customer area (E-124, E-143). Expects $site. --}}
<x-side-menu :pretitle="__('my.sites.title')" :title="$site->display_domain">
    <x-nav-link route="my.sites.show" :params="$site" icon="layout-dashboard">{{ __('sites.menu.overview') }}</x-nav-link>
    @can('checkDns', $site)
        <x-nav-link route="my.sites.dns" :params="$site" icon="world-www" active="my.sites.dns*">{{ __('sites.menu.dns') }}</x-nav-link>
    @endcan
    @can('changePhp', $site)
        <x-nav-link route="my.sites.php.show" :params="$site" icon="brand-php">{{ __('sites.menu.php') }}</x-nav-link>
    @endcan
    @can('viewAny', [\App\Models\Database::class, $site])
        <x-nav-link route="my.databases.index" :params="$site" icon="database" active="my.databases.*">{{ __('sites.menu.databases') }}</x-nav-link>
    @endcan
    @can('manageCron', $site)
        <x-nav-link route="my.sites.cron" :params="$site" icon="clock">{{ __('sites.menu.cron') }}</x-nav-link>
    @endcan
    @can('manageSftp', $site)
        <x-nav-link route="my.sites.sftp" :params="$site" icon="folder-up" active="my.sites.sftp*">{{ __('sites.menu.sftp') }}</x-nav-link>
    @endcan
    @can('manageBackups', $site)
        <x-nav-link route="my.sites.backups" :params="$site" icon="database-export" active="my.sites.backups*">{{ __('sites.menu.backups') }}</x-nav-link>
    @endcan
    @can('viewLogs', $site)
        <x-nav-link route="my.sites.logs" :params="$site" icon="file-text">{{ __('sites.menu.logs') }}</x-nav-link>
    @endcan
    <x-nav-link route="my.sites" icon="arrow-left">{{ __('my.sites.all') }}</x-nav-link>
</x-side-menu>
