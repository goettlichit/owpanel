{{-- Left menu of the website area. --}}
<x-side-menu :title="__('ui.nav.sites')">
    <x-nav-link route="sites.index" icon="world">{{ __('sites.all') }}</x-nav-link>
    @can('create', \App\Models\Site::class)
        <x-nav-link route="sites.create" icon="plus">{{ __('sites.create') }}</x-nav-link>
    @endcan
</x-side-menu>
