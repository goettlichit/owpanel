<x-layouts.app :title="__('servers.menu.load').' · '.$server->display_name">
    <x-slot:sidebar>
        @include('servers._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('servers.menu.load')" :pretitle="$server->display_name" />

    <livewire:server-load :server="$server" />
</x-layouts.app>
