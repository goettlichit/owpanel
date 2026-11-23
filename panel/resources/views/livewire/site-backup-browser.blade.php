<div class="d-flex flex-column gap-3">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">{{ __('backups.show.files') }}</h3>
            <div class="card-actions" wire:loading.flex>
                <span class="d-inline-flex align-items-center gap-2 small text-secondary">
                    <span class="spinner-border spinner-border-sm" role="status"></span>{{ __('backups.show.reading') }}
                </span>
            </div>
        </div>
        <div class="card-body py-2 border-bottom">
            <ol class="breadcrumb mb-0">
                <li @class(['breadcrumb-item', 'active' => $parts === []])>
                    @if ($parts === [])
                        <span class="font-monospace">htdocs</span>
                    @else
                        <a href="#" wire:click.prevent="go(0)" class="font-monospace">htdocs</a>
                    @endif
                </li>
                @foreach ($parts as $i => $part)
                    <li @class(['breadcrumb-item', 'active' => $loop->last])>
                        @if ($loop->last)
                            <span class="font-monospace">{{ $part }}</span>
                        @else
                            <a href="#" wire:click.prevent="go({{ $i + 1 }})" class="font-monospace">{{ $part }}</a>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
        @if (isset($answer['error']))
            <div class="card-body text-danger">{{ __('backups.show.error', ['error' => \Illuminate\Support\Facades\Lang::has('tasks.errors.'.$answer['error']) ? __('tasks.errors.'.$answer['error']) : $answer['error']]) }}</div>
        @elseif ($answer['files'] === [])
            <div class="card-body text-secondary">{{ __('backups.show.empty') }}</div>
        @else
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('backups.show.name') }}</th>
                            <th scope="col">{{ __('backups.list.size') }}</th>
                            <th scope="col">{{ __('backups.show.changed') }}</th>
                            <th scope="col"><span class="visually-hidden">{{ __('backups.show.restore') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($answer['files'] as $entry)
                            @php($entryPath = $path === '' ? $entry['name'] : $path.'/'.$entry['name'])
                            <tr wire:key="{{ $entryPath }}">
                                <td class="text-break" style="min-width: 12rem">
                                    @if ($entry['type'] === 'dir')
                                        <a href="#" wire:click.prevent="open(@js($entry['name']))" class="d-inline-flex align-items-center gap-2">
                                            <x-icon name="folder" />{{ $entry['name'] }}
                                        </a>
                                    @else
                                        <span class="d-inline-flex align-items-center gap-2">
                                            <x-icon :name="$entry['type'] === 'symlink' ? 'link' : 'file'" class="text-secondary" />{{ $entry['name'] }}
                                        </span>
                                    @endif
                                </td>
                                <td class="text-nowrap text-secondary">{{ $entry['size'] !== null ? \App\Support\Units::bytes($entry['size']) : '' }}</td>
                                <td class="text-nowrap text-secondary small">@if ($entry['mtime'])<x-time :value="\Illuminate\Support\Carbon::parse($entry['mtime'])" />@endif</td>
                                <td class="text-end">
                                    <details class="ow-inline-confirm d-inline-block">
                                        <summary class="btn btn-sm btn-outline-secondary">{{ __('backups.show.restore') }}</summary>
                                        <form data-ow-busy="{{ __('tasks.busy.restore') }}" data-ow-task="site-restore" method="POST" action="{{ route($area.'sites.backups.restore', [$site, $snapshot]) }}" class="mt-2 text-start" style="max-width: 18rem">
                                            @csrf
                                            <input type="hidden" name="scope" value="path">
                                            <input type="hidden" name="path" value="{{ $entryPath }}">
                                            <input type="hidden" name="confirm" value="1">
                                            <p class="small text-secondary mb-2">{{ __($entry['type'] === 'dir' ? 'backups.show.confirm_dir' : 'backups.show.confirm_file') }}</p>
                                            <button type="submit" class="btn btn-sm btn-warning">{{ __('backups.show.restore_now') }}</button>
                                        </form>
                                    </details>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($answer['more'])
                <div class="card-footer small text-secondary">{{ __('backups.show.more') }}</div>
            @endif
        @endif
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">{{ __('backups.show.databases') }}</h3>
        </div>
        @if (isset($answer['error']))
            <div class="card-body text-secondary">–</div>
        @elseif ($answer['databases'] === [])
            <div class="card-body text-secondary">{{ __('backups.show.no_databases') }}</div>
        @else
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <tbody>
                        @foreach ($answer['databases'] as $database)
                            <tr wire:key="db-{{ $database['name'] }}">
                                <td class="font-monospace">
                                    <span class="d-inline-flex align-items-center gap-2"><x-icon name="database" class="text-secondary" />{{ $database['name'] }}</span>
                                </td>
                                <td class="text-nowrap text-secondary">{{ $database['size'] !== null ? \App\Support\Units::bytes($database['size']) : '' }}</td>
                                <td class="text-end">
                                    <details class="ow-inline-confirm d-inline-block">
                                        <summary class="btn btn-sm btn-outline-secondary">{{ __('backups.show.restore') }}</summary>
                                        <form data-ow-busy="{{ __('tasks.busy.restore') }}" data-ow-task="site-restore" method="POST" action="{{ route($area.'sites.backups.restore', [$site, $snapshot]) }}" class="mt-2 text-start" style="max-width: 18rem">
                                            @csrf
                                            <input type="hidden" name="scope" value="database">
                                            <input type="hidden" name="database" value="{{ $database['name'] }}">
                                            <input type="hidden" name="confirm" value="1">
                                            <p class="small text-secondary mb-2">{{ __('backups.show.confirm_database') }}</p>
                                            <button type="submit" class="btn btn-sm btn-warning">{{ __('backups.show.restore_now') }}</button>
                                        </form>
                                    </details>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
