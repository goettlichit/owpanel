@php($area = \App\Support\Area::prefix())
<x-layouts.app :title="$table.' · '.$database->name">
    {{-- Shared by the administration and the customer area (E-144). --}}
    <x-slot:sidebar>
        @include($area === '' ? 'sites._site-menu' : 'my.sites._menu')
    </x-slot:sidebar>

    <x-page-header :title="$table" :pretitle="__('databases.tool.table.pretitle').' · '.$database->name">
        <x-slot:actions>
            @include('databases._tool-nav')
        </x-slot:actions>
    </x-page-header>

    @include('databases._error')

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route($area.'databases.tables', $database) }}" class="btn btn-sm btn-outline-secondary">
            <x-icon name="arrow-left" class="me-1" />{{ __('databases.tool.table.back') }}
        </a>
        <a href="{{ route($area.'databases.sql', [$database, 'table' => $table]) }}" class="btn btn-sm btn-outline-secondary">
            <x-icon name="terminal-2" class="me-1" />{{ __('databases.tool.table.in_sql') }}
        </a>
    </div>

    @if ($rows)
        @php($from = ($page - 1) * \App\Http\Controllers\DatabaseToolController::PAGE_SIZE + 1)
        <x-card :title="__('databases.tool.table.rows_title')" :flush="true">
            <x-slot:actions>
                <span class="text-secondary small">
                    @if ($rows->rows === [])
                        {{ __('databases.tool.table.count', ['count' => \App\Support\Units::number($count ?? 0, 0)]) }}
                    @else
                        {{ __('databases.tool.table.range', ['from' => \App\Support\Units::number($from, 0), 'to' => \App\Support\Units::number($from + count($rows->rows) - 1, 0), 'count' => \App\Support\Units::number($count ?? 0, 0)]) }}
                    @endif
                </span>
            </x-slot:actions>

            @if ($rows->rows === [])
                <div class="card-body text-secondary">{{ __('databases.tool.table.empty') }}</div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover card-table ow-db-table">
                        <thead>
                            <tr>
                                @foreach ($rows->columns as $i => $column)
                                    @php($position = $i + 1)
                                    @php($active = $sort === $position)
                                    @php($next = $active && ! $descending ? 'desc' : 'asc')
                                    <th scope="col" @class(['text-end' => $column['type'] === 'number']) @if ($active) aria-sort="{{ $descending ? 'descending' : 'ascending' }}" @endif>
                                        <a href="{{ route($area.'databases.table', [$database, 'name' => $table, 'sort' => $position, 'dir' => $next]) }}"
                                            class="text-reset text-decoration-none text-nowrap"
                                            title="{{ __('databases.tool.table.sort_'.$next, ['column' => $column['name']]) }}">
                                            {{ $column['name'] }}
                                            @if ($active)
                                                <x-icon :name="$descending ? 'chevron-down' : 'chevron-up'" />
                                            @endif
                                        </a>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows->rows as $row)
                                <tr>
                                    @foreach ($row as $i => $cell)
                                        <td @class(['text-end' => $rows->columns[$i]['type'] === 'number'])><x-db-cell :value="$cell" :number="$rows->columns[$i]['type'] === 'number'" /></td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($pages > 1)
                @php($link = fn (int $to) => route($area.'databases.table', array_filter([$database, 'name' => $table, 'sort' => $sort, 'dir' => $sort ? ($descending ? 'desc' : 'asc') : null, 'page' => $to])))
                <x-slot:footer>
                    <div class="d-flex align-items-center justify-content-between gap-2">
                        <a href="{{ $page > 1 ? $link($page - 1) : '#' }}" @class(['btn btn-sm btn-outline-secondary', 'disabled' => $page <= 1]) @if ($page <= 1) aria-disabled="true" @endif>
                            <x-icon name="chevron-left" class="me-1" />{{ __('databases.tool.table.previous') }}
                        </a>
                        <span class="text-secondary small">{{ __('databases.tool.table.page', ['page' => $page, 'pages' => $pages]) }}</span>
                        <a href="{{ $page < $pages ? $link($page + 1) : '#' }}" @class(['btn btn-sm btn-outline-secondary', 'disabled' => $page >= $pages]) @if ($page >= $pages) aria-disabled="true" @endif>
                            {{ __('databases.tool.table.next') }}<x-icon name="chevron-right" class="ms-1" />
                        </a>
                    </div>
                </x-slot:footer>
            @endif
        </x-card>
    @endif

    @if ($columns !== [])
        <x-card :title="__('databases.tool.table.columns_title')" :flush="true" class="mt-3">
            <div class="table-responsive">
                <table class="table table-sm table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('databases.tool.table.column') }}</th>
                            <th scope="col">{{ __('databases.tool.table.type') }}</th>
                            <th scope="col">{{ __('databases.tool.table.nullable') }}</th>
                            <th scope="col">{{ __('databases.tool.table.key') }}</th>
                            <th scope="col">{{ __('databases.tool.table.extra') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($columns as $column)
                            <tr>
                                <td class="fw-medium">{{ $column['COLUMN_NAME'] }}</td>
                                <td><code>{{ $column['COLUMN_TYPE'] }}</code></td>
                                <td>{{ $column['IS_NULLABLE'] === 'YES' ? __('databases.tool.table.yes') : __('databases.tool.table.no') }}</td>
                                <td>{{ $column['COLUMN_KEY'] ?: '–' }}</td>
                                <td class="text-secondary">{{ $column['EXTRA'] ?: '–' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif
</x-layouts.app>
