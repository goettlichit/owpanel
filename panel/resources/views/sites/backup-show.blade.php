@php($area = \App\Support\Area::prefix())
<x-layouts.app :title="__('backups.show.title', ['id' => $snapshot]).' · '.$site->display_domain">
    {{-- Shared by the administration and the customer area (E-145). --}}
    <x-slot:sidebar>
        @include($area === '' ? 'sites._site-menu' : 'my.sites._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('backups.show.title', ['id' => $snapshot])" :pretitle="$site->display_domain">
        <x-slot:actions>
            <a href="{{ route($area.'sites.backups', $site) }}" class="btn btn-outline-secondary">
                <x-icon name="arrow-left" class="me-1" />{{ __('backups.show.back') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Info box top right (docs/DESIGN.md, "Info-Boxen"). --}}
    <div class="row row-cards align-items-start">
        <div class="col-lg-8">
            @if ($missing && Gate::allows('configureBackups', $site))
            {{-- The server lacks the website: build it again from this backup (E-106). --}}
            <form data-ow-busy="{{ __('tasks.busy.rebuild') }}" data-ow-task="site-create" method="POST" action="{{ route('sites.backups.rebuild.snapshot', [$site, $snapshot]) }}">
                @csrf
                <x-card :title="__('backups.rebuild.title')" accent="orange">
                    <p class="text-secondary">{{ __('backups.rebuild.snapshot_text') }}</p>
                    <label class="form-check mb-0">
                        <input type="checkbox" name="confirm" value="1" @class(['form-check-input', 'is-invalid' => $errors->has('confirm')])>
                        <span class="form-check-label">{{ __('backups.rebuild.confirm') }}</span>
                        @error('confirm')<div class="invalid-feedback">{{ __('backups.rebuild.confirm_missing') }}</div>@enderror
                    </label>
                    <x-slot:footer>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <button type="submit" class="btn btn-warning" @disabled($busy)>
                                <x-icon name="history" class="me-1" />{{ __('backups.rebuild.submit') }}
                            </button>
                            <span class="small text-secondary">{{ __('backups.rebuild.steps') }}</span>
                        </div>
                    </x-slot:footer>
                </x-card>
            </form>
            @elseif (! $missing)
            <form data-ow-busy="{{ __('tasks.busy.restore') }}" data-ow-task="site-restore" method="POST" action="{{ route($area.'sites.backups.restore', [$site, $snapshot]) }}">
                @csrf
                <x-card :title="__('backups.restore.title')" accent="orange">
                    @if ($selfService)
                        @include('sites._self-service', ['block' => true])
                    @endif
                    <p class="text-secondary">{{ __('backups.restore.text') }}</p>
                    <div class="mb-3">
                        @foreach (['all', 'files', 'databases'] as $scope)
                            <label class="form-check">
                                <input type="radio" class="form-check-input" name="scope" value="{{ $scope }}" @checked(old('scope', 'all') === $scope)>
                                <span class="form-check-label">{{ __('backups.restore.scopes.'.$scope) }}</span>
                                <span class="form-check-description">{{ __('backups.restore.scope_hints.'.$scope) }}</span>
                            </label>
                        @endforeach
                    </div>
                    <label class="form-check mb-0">
                        <input type="checkbox" name="confirm" value="1" @class(['form-check-input', 'is-invalid' => $errors->has('confirm')])>
                        <span class="form-check-label">{{ __('backups.restore.confirm') }}</span>
                        @error('confirm')<div class="invalid-feedback">{{ __('backups.restore.confirm_missing') }}</div>@enderror
                    </label>
                    <x-slot:footer>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <button type="submit" class="btn btn-warning" @disabled($busy || ($selfService && ! $selfService['possible']))>
                                <x-icon name="history" class="me-1" />{{ __('backups.restore.submit') }}
                            </button>
                            @if ($selfService)
                                @include('sites._self-service', ['block' => false])
                            @endif
                            @if ($lastRestore?->status === \App\Enums\TaskStatus::Failed)
                                <div class="ms-auto">
                                    <livewire:task-badge :task="$lastRestore" :label="__('backups.restore.last')" :key="$lastRestore->id" />
                                </div>
                            @endif
                        </div>
                    </x-slot:footer>
                </x-card>
            </form>
            @endif
        </div>

        <div class="col-lg-4">
            <x-card :title="__('backups.restore.rules_title')" tone="info">
                <ul class="mb-0 ps-3">
                    <li>{{ __('backups.restore.rules.safety') }}</li>
                    <li>{{ __('backups.restore.rules.files') }}</li>
                    <li>{{ __('backups.restore.rules.databases') }}</li>
                    <li>{{ __('backups.restore.rules.site') }}</li>
                </ul>
            </x-card>
        </div>

        <div class="col-12">
            <livewire:site-backup-browser :site="$site" :snapshot="$snapshot" :area="$area" />
        </div>
    </div>
</x-layouts.app>
