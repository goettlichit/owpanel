<x-layouts.app :title="__('logs.title').' · '.$server->display_name">
    <x-slot:sidebar>
        @include('servers._menu')
    </x-slot:sidebar>

    @php($url = fn (string $key, int $count) => route('servers.logs', [$server, 'log' => $key, 'lines' => $count]))

    <x-page-header :title="__('logs.title')" :pretitle="$server->display_name">
        <x-slot:actions>
            @include('logs._switch')
        </x-slot:actions>
    </x-page-header>

    {{-- Read from the server after the page has loaded. --}}
    <livewire:log-viewer :target="\App\Jobs\ReadLog::SERVER" :subject="$server" :log="$log" :lines="$lines" route="servers.logs" :key="$log.'-'.$lines" />
</x-layouts.app>
