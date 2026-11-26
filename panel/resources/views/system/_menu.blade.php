{{-- Side menu of the "System" area: settings first, logs at the end. --}}
<x-side-menu :title="__('ui.nav.system')">
    @can('viewAny', App\Models\User::class)
        <x-nav-link route="users.index" active="users.*" icon="users">{{ __('users.title') }}</x-nav-link>
    @endcan
    @can('manage', App\Models\Setting::class)
        <x-nav-link route="settings.edit" icon="adjustments">{{ __('settings.title') }}</x-nav-link>
        <x-nav-link route="settings.mail" icon="mail">{{ __('settings.mail.title') }}</x-nav-link>
        <x-nav-link route="settings.oidc" icon="fingerprint">{{ __('settings.oidc.menu') }}</x-nav-link>
        <x-nav-link route="settings.gateway" icon="door-enter">{{ __('settings.ingress.menu') }}</x-nav-link>
        <x-nav-link route="settings.services" icon="plug-connected">{{ __('settings.services.title') }}</x-nav-link>
        <x-nav-link route="settings.backup" icon="database-export">{{ __('settings.backup_page.title') }}</x-nav-link>
        <x-nav-link route="workers.index" icon="activity-heartbeat">{{ __('workers.title') }}</x-nav-link>
    @endcan
    @can('viewAny', App\Models\Site::class)
        <x-nav-link route="statistics.index" icon="chart-bar">{{ __('statistics.title') }}</x-nav-link>
    @endcan
    <x-nav-link route="audit-log.index" icon="list-search">{{ __('audit.title') }}</x-nav-link>
    @can('viewAny', App\Models\Server::class)
        <x-nav-link route="logs.panel" icon="file-text">{{ __('logs.panel_title') }}</x-nav-link>
    @endcan
</x-side-menu>
