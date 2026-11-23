{{--
    Main layout (E-057). Row one: logo, "Open Web Panel", greeting, language
    and appearance. Row two: main areas and the user. Below: the menu of the
    selected object on the left (optional slot "sidebar") and the content,
    centred and at most about 1400 px wide (E-061, E-065).

    <x-layouts.app :title="…">
        <x-slot:sidebar><x-side-menu …>…</x-side-menu></x-slot:sidebar>
        …
    </x-layouts.app>
--}}
@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme-preference="{{ \App\Support\Preferences::theme()->value }}">
<head>
    <x-head :title="$title" />
</head>
<body>
    <a href="#content" class="visually-hidden-focusable">{{ __('ui.skip_to_content') }}</a>
    <div class="page">
        <x-topbar />

        <nav class="ow-mainnav d-print-none" aria-label="{{ __('ui.nav.main') }}">
            <div class="ow-bar">
                @if (auth()->user()?->isCustomer())
                    {{-- Customer area: own navigation (E-124). --}}
                    <x-main-nav-link route="my.sites" active="my.*" icon="world">{{ __('my.sites.title') }}</x-main-nav-link>
                @else
                    <x-main-nav-link route="dashboard" icon="layout-dashboard">{{ __('ui.nav.dashboard') }}</x-main-nav-link>
                @endif
                @can('viewAny', \App\Models\Site::class)
                    <x-main-nav-link route="sites.index" active="sites.*" icon="world">{{ __('ui.nav.sites') }}</x-main-nav-link>
                @endcan
                @can('viewAny', \App\Models\Customer::class)
                    <x-main-nav-link route="customers.index" active="customers.*" icon="users-group">{{ __('ui.nav.customers') }}</x-main-nav-link>
                @endcan
                @can('viewAny', \App\Models\Server::class)
                    <x-main-nav-link route="servers.index" active="servers.*" icon="server">{{ __('ui.nav.servers') }}</x-main-nav-link>
                @endcan
                @can('viewAny', \App\Models\ServerTask::class)
                @endcan
                @can('viewAny', \App\Models\AuditLog::class)
                    <x-main-nav-link route="audit-log.index" :active="['audit-log.*', 'users.*', 'settings.*', 'logs.*', 'workers.*']" icon="settings">{{ __('ui.nav.system') }}</x-main-nav-link>
                @endcan
                <x-user-menu />
            </div>
        </nav>

        {{-- Messages float at the top centre over the content (docs/DESIGN.md, "Hinweise"). --}}
        <div class="ow-toasts d-print-none">
            <div class="ow-toasts-stack" aria-live="polite" data-close-label="{{ __('ui.close') }}">
                <x-flash :toast="true" />
            </div>
        </div>

        <div class="ow-body">
            {{ $sidebar ?? '' }}
            <main id="content" class="ow-content">
                <x-paused-notice />
                <x-limit-confirm />
                @auth
                    {{-- Waits for the server tasks of the page and reloads it when they are done (E-152). --}}
                    @php([$taskScopeType, $taskScopeSubject] = \App\Support\TaskScope::current())
                    <livewire:task-watcher :type="$taskScopeType" :subject="$taskScopeSubject" />
                @endauth
                @isset($suspendedSite)
                    {{-- Suspended website (E-127): banner, and its forms are disabled
                         except where administrators may still act. --}}
                    <x-suspended-notice :site="$suspendedSite" />
                    @if (! ($suspendedFormsOpen ?? false))
                        <fieldset disabled class="ow-suspended">{{ $slot }}</fieldset>
                    @else
                        {{ $slot }}
                    @endif
                @else
                    {{ $slot }}
                @endisset
            </main>
        </div>

        <x-footer />
    </div>
</body>
</html>
