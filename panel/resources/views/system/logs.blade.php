<x-layouts.app :title="__('logs.panel_title')">
    <x-slot:sidebar>
        @include('system._menu')
    </x-slot:sidebar>

    @php($url = fn (string $key, int $count) => route('logs.panel', ['log' => $key, 'lines' => $count]))

    <x-page-header :title="__('logs.panel_title')" :pretitle="__('ui.nav.system')">
        <x-slot:actions>
            @include('logs._switch')
        </x-slot:actions>
    </x-page-header>

    @include('logs._viewer')
</x-layouts.app>
