<x-layouts.app :title="__('server_backups.panel_show.title', ['id' => $snapshot]).' · '.$server->display_name">
    <x-slot:sidebar>
        @include('servers._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('server_backups.panel_show.title', ['id' => $snapshot])" :pretitle="$server->display_name">
        <x-slot:actions>
            <a href="{{ route('servers.backups', $server) }}" class="btn btn-outline-secondary">
                <x-icon name="arrow-left" class="me-1" />{{ __('server_backups.panel_show.back') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Info box top right (docs/DESIGN.md, "Info-Boxen"). --}}
    <div class="row row-cards align-items-start">
        <div class="col-lg-8">
            <livewire:panel-backup-files :server="$server" :snapshot="$snapshot" />
        </div>
        <div class="col-lg-4">
            <x-card :title="__('server_backups.panel_show.restore_title')" tone="info">
                <ul class="mb-0 ps-3">
                    <li>{{ __('server_backups.panel_show.restore.why') }}</li>
                    <li>{{ __('server_backups.panel_show.restore.how') }}</li>
                    <li>{{ __('server_backups.panel_show.restore.data') }}</li>
                </ul>
            </x-card>
        </div>
    </div>
</x-layouts.app>
