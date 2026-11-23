<div @if ($running) wire:poll.3s @endif>
    @if ($sites->isEmpty())
        <div class="card-body text-secondary">{{ __('server_backups.no_sites') }}</div>
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th scope="col">{{ __('server_backups.site') }}</th>
                        <th scope="col">{{ __('server_backups.daily') }}</th>
                        <th scope="col">{{ __('backups.list.kind') }}</th>
                        <th scope="col">{{ __('server_backups.last') }}</th>
                        <th scope="col"><span class="visually-hidden">{{ __('server_backups.open') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sites as $site)
                        @php($last = $lastBackups->get($site->getKey()))
                        <tr>
                            <td>
                                <a href="{{ route('sites.backups', $site) }}">{{ $site->display_domain }}</a>
                                @if ($missing->contains($site))
                                    <span class="badge bg-orange-lt ms-1">{{ __('server_backups.missing.badge') }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($site->backup_enabled)
                                    <span class="badge bg-green-lt">{{ __('backups.nightly.on') }}</span>
                                @else
                                    <span class="badge bg-secondary-lt">{{ __('backups.nightly.off') }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($last)
                                    {{-- The kind of the last backup, coloured as in the lists of backups. --}}
                                    @php($kind = in_array('--manual', $last->arguments ?? [], true) ? 'manual' : 'schedule')
                                    <span class="badge bg-{{ $kind === 'manual' ? 'green' : 'blue' }}-lt">{{ __('backups.kinds.'.$kind) }}</span>
                                @else
                                    <span class="text-secondary">–</span>
                                @endif
                            </td>
                            <td>
                                @if ($last)
                                    <a href="{{ route('tasks.show', $last) }}" class="text-reset text-decoration-none d-inline-flex align-items-center flex-wrap gap-2 text-nowrap">
                                        <x-time :value="$last->created_at" /> <x-status-badge :status="$last->status->value" />
                                    </a>
                                @else
                                    <span class="text-secondary">{{ __('server_backups.never') }}</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('sites.backups', $site) }}" class="btn btn-sm btn-icon btn-outline-primary" aria-label="{{ __('server_backups.open') }}" title="{{ __('server_backups.open') }}">
                                    <x-icon name="folder-open" />
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
