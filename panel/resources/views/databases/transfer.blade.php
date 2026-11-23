@php($area = \App\Support\Area::prefix())
<x-layouts.app :title="__('databases.transfer.title').' · '.$database->name">
    {{-- Shared by the administration and the customer area (E-144). --}}
    <x-slot:sidebar>
        @include($area === '' ? 'sites._site-menu' : 'my.sites._menu')
    </x-slot:sidebar>

    <x-page-header :title="$database->name" :pretitle="__('databases.title').' · '.$site->display_domain">
        <x-slot:actions>
            @include('databases._tool-nav')
        </x-slot:actions>
    </x-page-header>

    @if ($busy)
        <x-card accent="orange" class="mb-3">
            <div class="d-flex gap-2"><x-icon name="loader-2" class="mt-1" />{{ __('databases.transfer.busy') }}</div>
        </x-card>
    @endif

    <div class="row row-cards">
        {{-- Export --}}
        <div class="col-lg-6">
            <form data-ow-busy="{{ __('tasks.busy.db_export') }}" data-ow-task="db-export" method="POST" action="{{ route($area.'databases.export', $database) }}" class="h-100" novalidate>
                @csrf
                <x-card :title="__('databases.transfer.export_title')" class="h-100">
                    <p class="text-secondary">{{ __('databases.transfer.export_text') }}</p>

                    @if ($exports->isNotEmpty())
                        <div class="fw-medium mb-2">{{ __('databases.transfer.available') }}</div>
                        <div class="list-group list-group-flush mb-0">
                            @foreach ($exports as $export)
                                <div class="list-group-item px-0 d-flex align-items-center gap-2">
                                    <x-icon name="file-zip" class="text-secondary" />
                                    <div class="me-auto">
                                        <x-time :value="$export->finished_at" />
                                        <span class="text-secondary small ms-1">{{ \App\Support\Units::bytes((int) filesize(\App\Databases\Transfers::path($export->transfer_file))) }}</span>
                                    </div>
                                    <a href="{{ route($area === '' ? 'tasks.download' : 'my.databases.download', $export) }}" class="btn btn-sm btn-outline-primary">
                                        <x-icon name="download" class="me-1" />{{ __('databases.transfer.download') }}
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <x-slot:footer>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <button type="submit" class="btn btn-primary" @disabled($busy)>
                                <x-icon name="database-export" class="me-1" />{{ __('databases.transfer.export_submit') }}
                            </button>
                            <span class="text-secondary small">{{ __('databases.transfer.kept', ['hours' => \App\Databases\Transfers::KEEP_HOURS]) }}</span>
                        </div>
                    </x-slot:footer>
                </x-card>
            </form>
        </div>

        {{-- Import: can overwrite tables, so orange --}}
        <div class="col-lg-6">
            <form data-ow-busy="{{ __('tasks.busy.db_import') }}" data-ow-task="db-import" method="POST" action="{{ route($area.'databases.import', $database) }}" enctype="multipart/form-data" class="h-100" novalidate>
                @csrf
                <x-card :title="__('databases.transfer.import_title')" tone="warning" class="h-100">
                    @if ($importBlocked)
                        {{-- Storage used up: the form stays visible, but disabled (E-126, E-146). --}}
                        <div class="alert alert-warning mb-3" role="status">{{ $importBlocked }}</div>
                    @endif
                    <fieldset @disabled($importBlocked)>
                    <p class="text-secondary">{{ __('databases.transfer.import_text') }}</p>

                    <div class="mb-3">
                        <label class="form-label required" for="dump">{{ __('databases.transfer.file') }}</label>
                        <input type="file" id="dump" name="dump" accept=".sql,.gz,application/sql,application/gzip" required aria-describedby="dump-hint"
                            @class(['form-control', 'is-invalid' => $errors->has('dump')])>
                        @error('dump')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-hint" id="dump-hint">{{ __('databases.transfer.file_hint', ['size' => $maxUploadMb]) }}</div>
                    </div>

                    <label @class(['form-check', 'is-invalid' => $errors->has('confirm')])>
                        <input type="checkbox" name="confirm" value="1" @class(['form-check-input', 'is-invalid' => $errors->has('confirm')])>
                        <span class="form-check-label">{{ __('databases.transfer.confirm', ['name' => $database->name]) }}</span>
                    </label>
                    @error('confirm')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                    </fieldset>

                    <x-slot:footer>
                        <button type="submit" class="btn btn-warning" @disabled($busy || $importBlocked)>
                            <x-icon name="database-import" class="me-1" />{{ __('databases.transfer.import_submit') }}
                        </button>
                    </x-slot:footer>
                </x-card>
            </form>
        </div>
    </div>
</x-layouts.app>
