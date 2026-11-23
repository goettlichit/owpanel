<x-layouts.app :title="__('server_backups.title').' · '.$server->display_name">
    <x-slot:sidebar>
        @include('servers._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('server_backups.title')" :pretitle="$server->display_name">
        @if ($server->backupActive())
            {{-- Everything at once: all websites and, on its server, the panel. --}}
            <x-slot:actions>
                <form data-ow-busy="{{ __('tasks.busy.backup_all') }}" data-ow-task="site-backup panel-backup" method="POST" action="{{ route('servers.backups.all', $server) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary">
                        <x-icon name="database-export" class="me-1" />{{ __('backups.create') }}
                    </button>
                </form>
            </x-slot:actions>
        @endif
    </x-page-header>

    <div class="row row-cards align-items-start">
        @unless ($hetznerConfigured)
            <div class="col-12">
                <x-card :title="__('snapshots.state.not_configured_title')" accent="orange">
                    <p class="mb-0">{{ __('settings.backup.needs_token') }}
                        <a href="{{ route('settings.services') }}">{{ __('settings.services.to_services') }}</a></p>
                </x-card>
            </div>
        @endunless

        {{-- Top: the backup target of this server, with the rules next to it. --}}
        <div class="col-lg-8">
            <form method="POST" action="{{ route('servers.backups.update', $server) }}" novalidate>
                @csrf
                @method('PUT')
                <x-card :title="__('settings.backup.title')" :accent="$accent">
                    <x-slot:actions>
                        <livewire:server-backup-state :server="$server" />
                    </x-slot:actions>
                    <p class="text-secondary">{{ __('settings.backup.text') }}</p>
                    @if ($boxesError)
                        <div class="alert alert-danger">{{ __('settings.backup.boxes_failed', ['error' => $boxesError]) }}</div>
                    @elseif ($hetznerConfigured && $boxes === [])
                        <div class="alert alert-warning">{{ __('settings.backup.no_boxes') }}</div>
                    @endif
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="backup_box">{{ __('settings.backup.host') }}</label>
                            <select id="backup_box" name="backup_box" @disabled(! $hetznerConfigured || $boxesError)
                                @class(['form-select', 'is-invalid' => $errors->has('backup_box')])>
                                <option value="">{{ __('settings.backup.none') }}</option>
                                @foreach ($boxes as $box)
                                    <option value="{{ $box['id'] }}" @selected((int) old('backup_box', $server->backup_box_id) === $box['id'])>
                                        {{ $box['name'] !== '' ? $box['name'].' · ' : '' }}{{ $box['username'] }}{{ $box['location'] !== '' ? ' · '.$box['location'] : '' }}
                                    </option>
                                @endforeach
                            </select>
                            @if ((! $hetznerConfigured || $boxesError) && $server->backup_box_id)
                                {{-- Keeps the target while the list cannot be loaded. --}}
                                <input type="hidden" name="backup_box" value="{{ $server->backup_box_id }}">
                            @endif
                            @error('backup_box')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="backup_path">{{ __('settings.backup.path') }}</label>
                            <input type="text" id="backup_path" name="backup_path" value="{{ old('backup_path', $server->backup_path) }}"
                                @class(['form-control', 'is-invalid' => $errors->has('backup_path')])>
                            @error('backup_path')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-hint">{{ __('settings.backup.path_hint', ['home' => \App\Backups\BackupTarget::homeDirectory($server)]) }}</div>
                        </div>
                        <div class="col-12">
                            <label class="form-check mb-0">
                                <input type="checkbox" class="form-check-input" name="backup_external" value="1" @checked(old('backup_external', $server->backup_external))>
                                <span class="form-check-label">{{ __('settings.backup.external') }}</span>
                                <span class="form-check-description">{{ __('settings.backup.external_hint') }}</span>
                            </label>
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label" for="backup_time">{{ __('settings.backup.time') }}</label>
                            <input type="time" id="backup_time" name="backup_time" value="{{ old('backup_time', $server->backup_time) }}"
                                @class(['form-control', 'is-invalid' => $errors->has('backup_time')])>
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label" for="backup_interval">{{ __('settings.backup.interval') }}</label>
                            <select id="backup_interval" name="backup_interval" @class(['form-select', 'is-invalid' => $errors->has('backup_interval')])>
                                @foreach (\App\Models\Server::BACKUP_INTERVALS as $hours)
                                    <option value="{{ $hours }}" @selected((int) old('backup_interval', $server->backup_interval) === $hours)>{{ __('settings.backup.intervals.'.$hours) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label" for="backup_keep_hourly">{{ __('settings.backup.hourly') }}</label>
                            <input type="number" id="backup_keep_hourly" name="backup_keep_hourly" min="1" max="168" value="{{ old('backup_keep_hourly', $server->backup_keep_hourly) }}" @class(['form-control', 'is-invalid' => $errors->has('backup_keep_hourly')])>
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label" for="backup_keep_daily">{{ __('settings.backup.daily') }}</label>
                            <input type="number" id="backup_keep_daily" name="backup_keep_daily" min="1" max="60" value="{{ old('backup_keep_daily', $server->backup_keep_daily) }}" class="form-control">
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label" for="backup_keep_weekly">{{ __('settings.backup.weekly') }}</label>
                            <input type="number" id="backup_keep_weekly" name="backup_keep_weekly" min="0" max="52" value="{{ old('backup_keep_weekly', $server->backup_keep_weekly) }}" class="form-control">
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label" for="backup_keep_monthly">{{ __('settings.backup.monthly') }}</label>
                            <input type="number" id="backup_keep_monthly" name="backup_keep_monthly" min="0" max="36" value="{{ old('backup_keep_monthly', $server->backup_keep_monthly) }}" class="form-control">
                        </div>
                    </div>
                    <div class="form-hint mt-2">{{ __('settings.backup.hint', ['zone' => config('owpanel.timezone')]) }}</div>
                    <x-slot:footer>
                        <button type="submit" class="btn btn-primary" @disabled(! $hetznerConfigured)><x-icon name="device-floppy" class="me-1" />{{ __('settings.backup.submit') }}</button>
                    </x-slot:footer>
                </x-card>
            </form>
        </div>

        {{-- Top, right: how it is backed up. --}}
        <div class="col-lg-4">
            <x-card :title="__('server_backups.rules_title')" tone="info">
                <ul class="mb-0 ps-3">
                    <li>{{ $server->backup_interval < 24 ? __('server_backups.rules.interval', ['time' => $server->backup_time, 'hours' => $server->backup_interval]) : __('server_backups.rules.daily', ['time' => $server->backup_time]) }}</li>
                    <li>{{ __($server->backup_interval < 24 ? 'server_backups.rules.retention_hourly' : 'server_backups.rules.retention', $server->backupRetention()) }}</li>
                    <li>{{ __('server_backups.rules.now') }}</li>
                    <li>{{ __('server_backups.rules.where') }}</li>
                    <li>{{ __('server_backups.rules.own') }}</li>
                    <li>{{ __('settings.backup.safety') }}</li>
                </ul>
            </x-card>
        </div>

        {{-- Below, across the full width: what is backed up on this server. --}}
        <div class="col-12 d-flex flex-column gap-3">
            @if ($missing->isNotEmpty())
                {{-- Websites the panel knows but the server lacks (E-106). --}}
                <form data-ow-busy="{{ __('tasks.busy.rebuild') }}" data-ow-task="site-create" method="POST" action="{{ route('servers.backups.rebuild', $server) }}">
                    @csrf
                    <x-card :title="__('server_backups.missing.title')" accent="orange">
                        <p class="text-secondary">{{ __('server_backups.missing.text') }}</p>
                        <ul class="mb-3 ps-3">
                            @foreach ($missing as $site)
                                <li><a href="{{ route('sites.backups', $site) }}">{{ $site->display_domain }}</a></li>
                            @endforeach
                        </ul>
                        @if ($server->backupActive())
                            <label class="form-check mb-0">
                                <input type="checkbox" name="confirm" value="1" @class(['form-check-input', 'is-invalid' => $errors->has('confirm')])>
                                <span class="form-check-label">{{ __('server_backups.missing.confirm') }}</span>
                                @error('confirm')<div class="invalid-feedback">{{ __('backups.rebuild.confirm_missing') }}</div>@enderror
                            </label>
                        @else
                            <p class="text-secondary mb-0">{{ __('server_backups.missing.not_ready') }}</p>
                        @endif
                        @if ($server->backupActive())
                            <x-slot:footer>
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <button type="submit" class="btn btn-warning">
                                        <x-icon name="history" class="me-1" />{{ __('server_backups.missing.submit') }}
                                    </button>
                                    <span class="small text-secondary">{{ __('backups.rebuild.steps') }}</span>
                                </div>
                            </x-slot:footer>
                        @endif
                    </x-card>
                </form>
            @endif

            @if ($server->hostsPanel())
                <x-card :title="__('settings.panel_backup.title')" :flush="true">
                    <x-slot:actions>
                        @if ($server->backupActive())
                            <form data-ow-busy="{{ __('tasks.busy.panel_backup') }}" data-ow-task="panel-backup" method="POST" action="{{ route('servers.backups.panel', $server) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-primary" @disabled($panelTask && ! $panelTask->isFinished())>
                                    <x-icon name="database-export" class="me-1" />{{ __('backups.create') }}
                                </button>
                            </form>
                        @endif
                    </x-slot:actions>
                    <div class="card-body">
                        <p class="text-secondary mb-2">{{ __('settings.panel_backup.text') }}</p>
                        <p class="text-secondary small mb-0">{{ __('settings.panel_backup.restore_hint') }}</p>
                        @if ($panelTask?->status === \App\Enums\TaskStatus::Failed)
                            <div class="mt-2"><livewire:task-badge :task="$panelTask" :label="__('backups.last')" :key="$panelTask->id" /></div>
                        @endif
                    </div>
                    @if ($server->backupActive())
                        <livewire:panel-backup-list :server="$server" />
                    @else
                        <div class="card-body border-top text-secondary">{{ __('settings.panel_backup.not_ready') }}</div>
                    @endif
                </x-card>
            @endif

            {{-- All websites, alphabetically, without pages. --}}
            <x-card :title="__('server_backups.sites')" :flush="true">
                <livewire:server-site-backups :server="$server" />
            </x-card>
        </div>
    </div>
</x-layouts.app>
