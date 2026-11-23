<div>
    @if ($answer === null)
        <div class="card-body text-secondary">{{ __('settings.panel_backup.not_ready') }}</div>
    @elseif (isset($answer['error']))
        <div class="card-body text-danger">{{ __('backups.list.error', ['error' => \Illuminate\Support\Facades\Lang::has('tasks.errors.'.$answer['error']) ? __('tasks.errors.'.$answer['error']) : $answer['error']]) }}</div>
    @elseif ($answer['snapshots'] === [])
        <div class="card-body text-secondary">{{ __('backups.list.empty') }}</div>
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th scope="col">{{ __('settings.panel_backup.id') }}</th>
                        <th scope="col">{{ __('server_backups.daily') }}</th>
                        <th scope="col">{{ __('backups.list.kind') }}</th>
                        <th scope="col">{{ __('backups.list.time') }}</th>
                        <th scope="col"><span class="visually-hidden">{{ __('backups.list.open') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($page as $snapshot)
                        <tr>
                            <td>
                                <a href="{{ route('servers.backups.panel.show', [$server, $snapshot['id']]) }}"><code>{{ $snapshot['id'] }}</code></a>
                                @if (($restoredId ?? null) === $snapshot['id'])
                                    <span class="badge bg-azure-lt ms-1" title="{{ __('settings.restored_badge_hint') }}">{{ __('settings.restored_badge') }}</span>
                                @endif
                            </td>
                            {{-- The panel is always backed up on schedule while its server is connected. --}}
                            <td><span class="badge bg-green-lt">{{ __('backups.nightly.on') }}</span></td>
                            <td>
                                @php($tone = ['manual' => 'green', 'restore' => 'orange'][$snapshot['kind']] ?? 'blue')
                                <span class="badge bg-{{ $tone }}-lt">{{ __('backups.kinds.'.$snapshot['kind']) }}</span>
                            </td>
                            <td>
                                {{-- Only finished backups reach the Storage Box. --}}
                                <span class="d-inline-flex align-items-center flex-wrap gap-2 text-nowrap">
                                    @if ($snapshot['time'])<x-time :value="\Illuminate\Support\Carbon::parse($snapshot['time'])" />@endif
                                    <x-status-badge status="succeeded" />
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="{{ route('servers.backups.panel.show', [$server, $snapshot['id']]) }}" class="btn btn-sm btn-icon btn-outline-primary" aria-label="{{ __('backups.list.open') }}" title="{{ __('backups.list.open') }}">
                                    <x-icon name="folder-open" />
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($pages > 1)
            {{-- Three per page; older ones by paging. --}}
            <div class="card-footer d-flex align-items-center justify-content-between gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="newer" @disabled($current <= 1)>
                    <x-icon name="chevron-left" class="me-1" />{{ __('backups.list.newer') }}
                </button>
                <span class="small text-secondary">{{ __('backups.list.page', ['page' => $current, 'pages' => $pages]) }}</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="older" @disabled($current >= $pages)>
                    {{ __('backups.list.older') }}<x-icon name="chevron-right" class="ms-1" />
                </button>
            </div>
        @endif
    @endif
</div>
