@php($area = \App\Support\Area::prefix())
<x-layouts.app :title="__('databases.tool.tables.title').' · '.$database->name">
    {{-- Shared by the administration and the customer area (E-144). --}}
    <x-slot:sidebar>
        @include($area === '' ? 'sites._site-menu' : 'my.sites._menu')
    </x-slot:sidebar>

    <x-page-header :title="$database->name" :pretitle="__('databases.title').' · '.$site->display_domain">
        <x-slot:actions>
            @include('databases._tool-nav')
        </x-slot:actions>
    </x-page-header>

    @include('databases._error')

    @php($size = array_sum(array_map(fn ($table) => $table['size'] ?? 0, $tables)))
    <x-card :title="__('databases.tool.tables.title')" :flush="true">
        @if (! $response->error && ! $response->sqlError)
            <x-slot:actions>
                <span class="text-secondary small">{{ __('databases.tool.tables.total', ['count' => count($tables), 'size' => \App\Support\Units::bytes($size)]) }}</span>
            </x-slot:actions>
        @endif

        @if ($tables === [])
            <div class="card-body text-secondary">{{ $response->error ? '–' : __('databases.tool.tables.empty') }}</div>
        @else
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('databases.tool.tables.name') }}</th>
                            <th scope="col" class="text-end" title="{{ __('databases.tool.tables.rows_hint') }}">{{ __('databases.tool.tables.rows') }}</th>
                            <th scope="col" class="text-end">{{ __('databases.tool.tables.size') }}</th>
                            <th scope="col">{{ __('databases.tool.tables.engine') }}</th>
                            <th scope="col">{{ __('databases.tool.tables.collation') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tables as $table)
                            <tr>
                                <td>
                                    <a href="{{ route($area.'databases.table', [$database, 'name' => $table['name']]) }}" class="fw-medium">{{ $table['name'] }}</a>
                                    @if ($table['view'])
                                        <span class="badge bg-azure-lt ms-1">{{ __('databases.tool.tables.view') }}</span>
                                    @endif
                                </td>
                                <td class="text-end">{{ $table['rows'] === null ? '–' : \App\Support\Units::number($table['rows'], 0) }}</td>
                                <td class="text-end text-nowrap">{{ $table['size'] === null ? '–' : \App\Support\Units::bytes($table['size']) }}</td>
                                <td class="text-secondary">{{ $table['engine'] ?? '–' }}</td>
                                <td class="text-secondary">{{ $table['collation'] ?? '–' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
</x-layouts.app>
