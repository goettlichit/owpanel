@php($area = \App\Support\Area::prefix())
<x-layouts.app :title="__('backups.title').' · '.$site->display_domain">
    {{-- Shared by the administration and the customer area (E-145). --}}
    <x-slot:sidebar>
        @include($area === '' ? 'sites._site-menu' : 'my.sites._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('backups.title')" :pretitle="$site->display_domain">
        @if ($ready && ! $missing)
            <x-slot:actions>
                <form data-ow-busy="{{ __('tasks.busy.backup') }}" data-ow-task="site-backup" method="POST" action="{{ route($area.'sites.backups.store', $site) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary" @disabled($busy || ($selfService && ! $selfService['possible']))>
                        <x-icon name="database-export" class="me-1" />{{ __('backups.create') }}
                    </button>
                </form>
            </x-slot:actions>
        @endif
    </x-page-header>

    <div class="row row-cards align-items-start">
        @unless ($configured)
            <div class="col-12">
                <x-card :title="__('backups.state.not_configured_title')" accent="orange">
                    <p class="mb-0">{{ __('backups.state.not_configured') }}@if ($area === '') <a href="{{ route('servers.backups', $site->server) }}">{{ __('backups.state.to_settings') }}</a>@endif</p>
                </x-card>
            </div>
        @elseif (! $ready)
            <div class="col-12">
                <x-card :title="__('backups.state.not_ready_title')" accent="orange">
                    <p class="mb-0">{{ __('backups.state.not_ready') }}@if ($area === '') <a href="{{ route('servers.backups', $site->server) }}">{{ __('backups.state.to_settings') }}</a>@endif</p>
                </x-card>
            </div>
        @endunless

        @if ($missing && $ready && Gate::allows('configureBackups', $site))
            {{-- The server lacks the website (E-106). --}}
            <div class="col-12">
                <form data-ow-busy="{{ __('tasks.busy.rebuild') }}" data-ow-task="site-create" method="POST" action="{{ route('sites.backups.rebuild', $site) }}">
                    @csrf
                    <x-card :title="__('backups.rebuild.title')" accent="orange">
                        <p class="text-secondary">{{ __('backups.rebuild.text', ['server' => $site->server->display_name]) }}</p>
                        <p class="text-secondary small">{{ __('backups.rebuild.latest_hint') }}</p>
                        <label class="form-check mb-0">
                            <input type="checkbox" name="confirm" value="1" @class(['form-check-input', 'is-invalid' => $errors->has('confirm')])>
                            <span class="form-check-label">{{ __('backups.rebuild.confirm') }}</span>
                            @error('confirm')<div class="invalid-feedback">{{ __('backups.rebuild.confirm_missing') }}</div>@enderror
                        </label>
                        <x-slot:footer>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <button type="submit" class="btn btn-warning" @disabled($busy)>
                                    <x-icon name="history" class="me-1" />{{ __('backups.rebuild.submit_latest') }}
                                </button>
                                <span class="small text-secondary">{{ __('backups.rebuild.steps') }}</span>
                            </div>
                        </x-slot:footer>
                    </x-card>
                </form>
            </div>
        @endif

        {{-- Info box top right (docs/DESIGN.md, "Info-Boxen"). --}}
        <div class="col-lg-8">
            <x-card :title="__('backups.nightly.title')" :accent="$site->backup_enabled ? 'green' : 'blue'">
                <x-slot:actions>
                    @if ($site->backup_enabled)
                        <span class="badge bg-green-lt">{{ __('backups.nightly.on') }}</span>
                    @else
                        <span class="badge bg-secondary-lt">{{ __('backups.nightly.off') }}</span>
                    @endif
                </x-slot:actions>
                {{-- At the top of the first card on the left, like above the forms of cron jobs and databases (E-146). --}}
                @if ($selfService)
                    @include('sites._self-service', ['block' => true])
                @endif
                <p class="text-secondary mb-0">{{ $site->server->backup_interval < 24 ? __('backups.nightly.text_interval', ['time' => $time, 'hours' => $site->server->backup_interval]) : __('backups.nightly.text', ['time' => $time]) }}</p>
                <x-slot:footer>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        @can('configureBackups', $site)
                            <form method="POST" action="{{ route('sites.backups.toggle', $site) }}">
                                @csrf
                                <button type="submit" @class(['btn', 'btn-outline-secondary' => $site->backup_enabled, 'btn-primary' => ! $site->backup_enabled])>
                                    {{ __($site->backup_enabled ? 'backups.nightly.disable' : 'backups.nightly.enable') }}
                                </button>
                            </form>
                        @endcan
                        @if ($selfService)
                            {{-- What the package still allows today (E-145). --}}
                            @include('sites._self-service', ['block' => false])
                        @endif
                        @if ($lastTask?->status === \App\Enums\TaskStatus::Failed)
                            <div class="ms-auto">
                                <livewire:task-badge :task="$lastTask" :label="__('backups.last')" :key="$lastTask->id" />
                            </div>
                        @endif
                    </div>
                </x-slot:footer>
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card :title="__('backups.rules.title')" tone="info">
                <ul class="mb-0 ps-3">
                    <li>{{ __('backups.rules.content') }}</li>
                    <li>{{ __($retention['hourly'] > 0 ? 'backups.rules.schedule_hourly' : 'backups.rules.schedule', $retention) }}</li>
                    <li>{{ __('backups.rules.manual') }}</li>
                    <li>{{ __('backups.rules.restore') }}</li>
                    <li>{{ __('backups.rules.encrypted') }}</li>
                </ul>
            </x-card>
        </div>

        <div class="col-12">
            <x-card :title="__('backups.list.title')" :flush="true">
                @if (! $ready)
                    <div class="card-body text-secondary">{{ __('backups.list.not_ready') }}</div>
                @elseif (! $active)
                    <div class="card-body text-secondary">{{ __('backups.list.not_active') }}</div>
                @else
                    <livewire:site-backup-list :site="$site" :area="$area" />
                @endif
                <x-slot:footer>
                    <div class="small text-secondary">{{ __('backups.list.hint') }}</div>
                </x-slot:footer>
            </x-card>
        </div>
    </div>
</x-layouts.app>
