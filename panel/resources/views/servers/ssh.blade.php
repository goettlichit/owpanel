<x-layouts.app :title="__('ssh.title').' · '.$server->display_name">
    <x-slot:sidebar>
        @include('servers._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('ssh.title')" :pretitle="$server->display_name" />

    {{-- Read from the server after the page has loaded. --}}
    <livewire:server-ssh :server="$server" />
</x-layouts.app>
