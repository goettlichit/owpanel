@php($area = \App\Support\Area::prefix())
<x-layouts.app :title="$database->name.' · '.$site->display_domain">
    {{-- Shared by the administration and the customer area (E-144). --}}
    <x-slot:sidebar>
        @include($area === '' ? 'sites._site-menu' : 'my.sites._menu')
    </x-slot:sidebar>

    <x-page-header :title="$database->name" :pretitle="__('databases.title').' · '.$site->display_domain">
        <x-slot:actions>
            @include('databases._tool-nav')
        </x-slot:actions>
    </x-page-header>

    {{-- Lasting state as a banner, as on the website page. --}}
    @if (in_array($database->status, [\App\Enums\SiteStatus::Pending, \App\Enums\SiteStatus::Deleting, \App\Enums\SiteStatus::Failed], true) || ($busy && $database->new_password))
        @php($failed = $database->status === \App\Enums\SiteStatus::Failed)
        <div @class(['alert', 'alert-danger' => $failed, 'alert-info' => ! $failed]) role="status">
            <div class="d-flex align-items-start gap-2 w-100">
                <x-icon :name="$failed ? 'alert-triangle' : 'loader-2'" class="mt-1" />
                <div class="flex-fill">
                    @if ($failed && $area !== '')
                        {{-- Customers get no task log and no error of the server (E-144). --}}
                        {{ __('my.databases.failed') }}
                    @elseif ($failed)
                        {{ __($lastTask?->script === \App\Enums\ServerScript::DbDelete ? 'databases.state.failed_delete' : 'databases.state.failed_create', ['error' => $lastTask?->errorMessage() ?? '–']) }}
                    @elseif ($database->status === \App\Enums\SiteStatus::Active)
                        {{ __('databases.state.password') }}
                    @else
                        {{ __('databases.state.'.$database->status->value) }}
                    @endif
                </div>
                @if ($lastTask && $area === '')
                    <a href="{{ route('tasks.show', $lastTask) }}" class="btn btn-sm btn-outline-secondary flex-shrink-0">
                        <x-icon name="list-check" class="me-1" />{{ __('sites.state.show_task') }}
                    </a>
                @endif
            </div>
        </div>
    @endif

    <div class="row row-cards">
        <div class="col-lg-6">
            <x-card :title="__('databases.credentials.title')" class="h-100"
                :accent="match ($database->status) { \App\Enums\SiteStatus::Active => 'green', \App\Enums\SiteStatus::Failed => 'red', default => 'orange' }">
                <x-slot:actions>
                    <x-status-badge :status="$database->status->value" />
                </x-slot:actions>

                <dl class="row mb-0">
                    <dt class="col-sm-4">{{ __('databases.credentials.host') }}</dt>
                    <dd class="col-sm-8"><code>{{ \App\Models\Database::HOST }}</code> <span class="text-secondary small">{{ __('databases.credentials.host_alt') }}</span></dd>

                    <dt class="col-sm-4">{{ __('databases.credentials.port') }}</dt>
                    <dd class="col-sm-8"><code>{{ \App\Models\Database::PORT }}</code></dd>

                    <dt class="col-sm-4">{{ __('databases.credentials.database') }}</dt>
                    <dd class="col-sm-8"><code>{{ $database->name }}</code><x-copy-button :value="$database->name" /></dd>

                    <dt class="col-sm-4">{{ __('databases.credentials.user') }}</dt>
                    <dd class="col-sm-8"><code>{{ $database->name }}</code><x-copy-button :value="$database->name" /></dd>

                    <dt class="col-sm-4">{{ __('databases.credentials.password') }}</dt>
                    <dd class="col-sm-8 mb-0">
                        @if ($revealed)
                            <code class="text-break">{{ $database->password }}</code><x-copy-button :value="$database->password" />
                            <div class="small text-secondary mt-1">{{ __('databases.credentials.revealed_hint') }}</div>
                        @else
                            <code>••••••••••••</code>
                            @can('revealPassword', $database)
                                <a href="{{ route($area.'databases.reveal', $database) }}" class="btn btn-sm btn-outline-secondary ms-2">
                                    <x-icon name="eye" class="me-1" />{{ __('databases.credentials.reveal') }}
                                </a>
                            @endcan
                        @endif
                    </dd>
                </dl>
            </x-card>
        </div>

        @can('changePassword', $database)
            <div class="col-lg-6">
                <form data-ow-busy="{{ __('tasks.busy.db_password') }}" data-ow-task="db-create" method="POST" action="{{ route($area.'databases.password', $database) }}" class="h-100">
                    @csrf
                    <x-card :title="__('databases.password.title')" tone="warning" class="h-100">
                        <p class="text-secondary mb-0">{{ __('databases.password.text') }}</p>
                        <x-slot:footer>
                            <button type="submit" class="btn btn-warning" @disabled($busy || $database->status !== \App\Enums\SiteStatus::Active)>
                                <x-icon name="key" class="me-1" />{{ __('databases.password.submit') }}
                            </button>
                        </x-slot:footer>
                    </x-card>
                </form>
            </div>

            @if ($database->status === \App\Enums\SiteStatus::Failed && ! $busy && $lastTask?->script === \App\Enums\ServerScript::DbCreate && Gate::allows('retry', $database))
                <div class="col-lg-6">
                    <form data-ow-busy="{{ __('tasks.busy.db_create') }}" data-ow-task="db-create" method="POST" action="{{ route('databases.retry', $database) }}" class="h-100">
                        @csrf
                        <x-card :title="__('databases.retry.title')" tone="info" class="h-100">
                            <p class="text-secondary mb-0">{{ __('databases.retry.text') }}</p>
                            <x-slot:footer>
                                <button type="submit" class="btn btn-primary">
                                    <x-icon name="refresh" class="me-1" />{{ __('databases.retry.submit') }}
                                </button>
                            </x-slot:footer>
                        </x-card>
                    </form>
                </div>
            @endif
        @endcan

        @can('delete', $database)
            <div class="col-lg-6">
                <form data-ow-busy="{{ __('tasks.busy.db_delete') }}" data-ow-task="db-delete" method="POST" action="{{ route($area.'databases.destroy', $database) }}" class="h-100" novalidate>
                    @csrf
                    @method('DELETE')
                    <x-card :title="__('databases.delete.title')" tone="danger" class="h-100">
                        <p class="text-secondary">{{ __('databases.delete.text') }}</p>
                        <label class="form-label required" for="confirm_name">{{ __('databases.delete.confirm') }}</label>
                        <input type="text" id="confirm_name" name="confirm_name" required autocomplete="off"
                            autocapitalize="none" spellcheck="false" placeholder="{{ $database->name }}" @disabled($busy)
                            @class(['form-control', 'is-invalid' => $errors->has('confirm_name')])>
                        @error('confirm_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        @if ($busy)
                            <div class="form-hint">{{ __('databases.delete.busy') }}</div>
                        @endif
                        <x-slot:footer>
                            <button type="submit" class="btn btn-danger" @disabled($busy)>
                                <x-icon name="trash" class="me-1" />{{ __('databases.delete.submit') }}
                            </button>
                        </x-slot:footer>
                    </x-card>
                </form>
            </div>
        @endcan

        {{-- Tasks with their logs only in the administration (E-143). --}}
        @if ($area === '')
            <div class="col-12">
                <x-card :title="__('sites.recent_tasks')" :flush="true">
                    @if ($tasks->isEmpty())
                        <div class="card-body text-secondary">{{ __('tasks.empty') }}</div>
                    @else
                        @include('tasks._table', ['tasks' => $tasks, 'showServer' => false])
                    @endif
                </x-card>
            </div>
        @endif
    </div>
</x-layouts.app>
