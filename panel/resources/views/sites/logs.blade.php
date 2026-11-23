<x-layouts.app :title="__('logs.title').' · '.$site->display_domain">
    {{-- Shared by the administration ($area "sites.") and the customer area ("my.sites.", E-143). --}}
    <x-slot:sidebar>
        @include($area === 'sites.' ? 'sites._site-menu' : 'my.sites._menu')
    </x-slot:sidebar>

    @php($url = fn (string $key, int $count) => route($area.'logs', [$site, 'log' => $key, 'lines' => $count]))

    <x-page-header :title="__('logs.title')" :pretitle="$site->display_domain">
        <x-slot:actions>
            @include('logs._switch')
        </x-slot:actions>
    </x-page-header>

    {{-- Read from the server after the page has loaded. --}}
    <livewire:log-viewer :target="\App\Jobs\ReadLog::SITE" :subject="$site" :log="$log" :lines="$lines" :route="$area.'logs'" :key="$log.'-'.$lines" />
</x-layouts.app>
