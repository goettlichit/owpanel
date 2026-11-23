@php($area = \App\Support\Area::prefix())
<x-layouts.app :title="__('databases.tool.sql.title').' · '.$database->name">
    {{-- Shared by the administration and the customer area (E-144). --}}
    <x-slot:sidebar>
        @include($area === '' ? 'sites._site-menu' : 'my.sites._menu')
    </x-slot:sidebar>

    <x-page-header :title="$database->name" :pretitle="__('databases.title').' · '.$site->display_domain">
        <x-slot:actions>
            @include('databases._tool-nav')
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route($area.'databases.run', $database) }}" class="mb-3" novalidate data-ow-submit-shortcut>
        @csrf
        <x-card :title="__('databases.tool.sql.title')">
            <label class="form-label visually-hidden" for="sql">{{ __('databases.tool.sql.label') }}</label>
            <textarea id="sql" name="sql" rows="8" required maxlength="{{ \App\Http\Controllers\DatabaseToolController::MAX_SQL_LENGTH }}"
                spellcheck="false" autocapitalize="none" autocomplete="off" autofocus aria-describedby="sql-hint"
                @class(['form-control font-monospace', 'is-invalid' => $errors->has('sql')])>{{ old('sql', $sql) }}</textarea>
            @error('sql')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
            <div class="form-hint" id="sql-hint">{{ __('databases.tool.sql.hint', ['name' => $database->name]) }}</div>
            <div class="form-hint text-warning">
                <x-icon name="alert-triangle" class="me-1" />{{ __('databases.tool.sql.warning') }}
            </div>

            <x-slot:footer>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <button type="submit" class="btn btn-primary">
                        <x-icon name="player-play" class="me-1" />{{ __('databases.tool.sql.submit') }}
                    </button>
                    <span class="text-secondary small"><kbd>{{ __('databases.tool.sql.shortcut') }}</kbd></span>
                    <span class="text-secondary small ms-auto">{{ __('databases.tool.sql.not_logged') }}</span>
                </div>
            </x-slot:footer>
        </x-card>
    </form>

    @if ($response)
        @if ($response->error)
            @include('databases._error')
        @else
            @foreach ($response->results as $result)
                <x-card :title="__('databases.tool.sql.statement', ['number' => $result->statement])" :flush="$result->hasRows() && $result->rows !== []" class="mb-3">
                    @if ($result->hasRows())
                    <x-slot:actions>
                        <span class="text-secondary small">
                            @if ($result->truncated())
                                {{ __('databases.tool.sql.truncated', ['shown' => \App\Support\Units::number(count($result->rows), 0), 'count' => \App\Support\Units::number($result->total, 0)]) }}
                            @else
                                {{ __('databases.tool.sql.rows', ['count' => \App\Support\Units::number($result->total, 0)]) }}
                            @endif
                        </span>
                    </x-slot:actions>
                    @endif

                    @if ($result->hasRows() && $result->rows !== [])
                        @include('databases._result-table', ['result' => $result])
                    @elseif ($result->hasRows())
                        <span class="text-secondary">{{ __('databases.tool.sql.no_rows') }}</span>
                    @else
                        <span class="text-secondary">{{ __('databases.tool.sql.affected', ['count' => \App\Support\Units::number(max(0, $result->affected), 0)]) }}</span>
                    @endif
                </x-card>
            @endforeach

            {{-- An SQL error stops the run, so it comes after the statements that ran. --}}
            @include('databases._error')
        @endif
    @endif
</x-layouts.app>
