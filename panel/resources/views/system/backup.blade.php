<x-layouts.app :title="__('settings.backup_page.title')">
    <x-slot:sidebar>
        @include('system._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('settings.backup_page.title')" :pretitle="__('ui.nav.system')" />

    @if ($restored && ! $restored['dismissed'])
        <div class="alert alert-info d-flex align-items-start gap-2" role="status">
            <x-icon name="history" class="mt-1" />
            <div class="flex-fill">
                {!! __('settings.restored', [
                    'restored' => e($restored['restored_at']->copy()->setTimezone(config('owpanel.timezone'))->isoFormat('L LT')),
                    'backup' => e($restored['backup_time']->copy()->setTimezone(config('owpanel.timezone'))->isoFormat('L LT')),
                    'id' => '<code>'.e($restored['snapshot']).'</code>',
                ]) !!}
            </div>
            {{-- Hidden for everyone; the backup stays marked in the list. --}}
            <form method="POST" action="{{ route('settings.backup.restored.dismiss') }}" class="ms-2">
                @csrf
                <button type="submit" class="btn-close" aria-label="{{ __('settings.restored_dismiss') }}" title="{{ __('settings.restored_dismiss') }}"></button>
            </form>
        </div>
    @endif

    <div class="row row-cards align-items-start">
        {{-- On top: the emergency data. --}}
        <div class="col-12">
            <form method="POST" action="{{ route('settings.backup.export') }}">
                @csrf
                <x-card :title="__('settings.backup_keys.title')" accent="orange">
                    <p class="text-secondary mb-2">{{ __('settings.backup_keys.button_hint') }}</p>
                    <p class="mb-2"><x-icon name="key" class="me-1 text-orange" />{{ __('settings.backup_keys.password_hint') }}</p>
                    <p class="text-secondary small mb-0">{{ __('settings.backup_keys.warning') }}</p>
                    <x-slot:footer>
                        <button type="submit" class="btn btn-outline-secondary">
                            <x-icon name="file-download" class="me-1" />{{ __('settings.backup_keys.button') }}
                        </button>
                    </x-slot:footer>
                </x-card>
            </form>
        </div>

        {{-- Below: snapshots and backups of the servers side by side. --}}
        <div class="col-lg-6">
            <x-card :title="__('settings.backup_page.snapshots')" :accent="$accents['snapshots']">
                <p class="text-secondary">{{ __('settings.backup_page.snapshots_text') }}</p>
                <ul class="list-unstyled mb-0">
                    @forelse ($servers as $server)
                        <li class="d-flex align-items-center gap-2 mb-2">
                            <a href="{{ route('servers.snapshots', $server) }}" class="fw-medium">{{ $server->display_name }}</a>
                            @if ($hetznerConfigured && $server->snapshot_enabled && $snapshotFailed($server))
                                <span class="badge bg-red-lt">{{ __('settings.backup_page.failed') }}</span>
                            @elseif ($hetznerConfigured && $server->snapshot_enabled)
                                <span class="badge bg-green-lt">{{ __('settings.backup_page.snapshots_on', ['time' => $server->snapshot_time]) }}</span>
                            @else
                                <span class="badge bg-secondary-lt">{{ __('settings.backup_page.snapshots_off') }}</span>
                            @endif
                        </li>
                    @empty
                        <li class="text-secondary">{{ __('settings.backup_page.no_servers') }}</li>
                    @endforelse
                </ul>
            </x-card>
        </div>
        <div class="col-lg-6">
            <x-card :title="__('settings.backup_page.backups')" :accent="$accents['backups']">
                <p class="text-secondary">{{ __('settings.backup_page.backups_text') }}</p>
                <ul class="list-unstyled mb-0">
                    @forelse ($servers as $server)
                        <li class="d-flex align-items-center gap-2 mb-2">
                            <a href="{{ route('servers.backups', $server) }}" class="fw-medium">{{ $server->display_name }}</a>
                            @if ($server->backupConfigured() && $backupFailed($server))
                                <span class="badge bg-red-lt">{{ __('settings.backup_page.failed') }}</span>
                            @elseif ($server->backupActive())
                                <span class="badge bg-green-lt">{{ $server->backup_interval < 24 ? __('settings.backup_page.backups_interval', ['time' => $server->backup_time, 'hours' => $server->backup_interval]) : __('settings.backup_page.backups_on', ['time' => $server->backup_time]) }}</span>
                            @elseif ($server->backupConfigured())
                                <span class="badge bg-orange-lt">{{ __('settings.backup.not_ready') }}</span>
                            @else
                                <span class="badge bg-secondary-lt">{{ __('settings.backup_page.snapshots_off') }}</span>
                            @endif
                        </li>
                    @empty
                        <li class="text-secondary">{{ __('settings.backup_page.no_servers') }}</li>
                    @endforelse
                </ul>
                @foreach ($storageBoxes as $box)
                    <div class="mt-3">
                        <div class="text-secondary small mb-1">{{ __('settings.backup_page.storage_box', ['name' => $box['name']]) }}</div>
                        <x-storage-bar :used="$box['used']" :limit="$box['total']" :measured="\Illuminate\Support\Carbon::parse($box['checked_at'])" />
                    </div>
                @endforeach
            </x-card>
        </div>
    </div>
</x-layouts.app>
