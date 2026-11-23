{{-- Switch between the pages of a database (E-079). Expects $database. --}}
@php($area = \App\Support\Area::prefix())
<div class="btn-group" role="group" aria-label="{{ $database->name }}">
    @foreach ([
        'overview' => [route($area.'databases.show', $database), request()->routeIs($area.'databases.show', $area.'databases.reveal'), 'info-circle'],
        'tables' => [route($area.'databases.tables', $database), request()->routeIs($area.'databases.tables', $area.'databases.table'), 'table'],
        'sql' => [route($area.'databases.sql', $database), request()->routeIs($area.'databases.sql', $area.'databases.run'), 'terminal-2'],
        'transfer' => [route($area.'databases.transfer', $database), request()->routeIs($area.'databases.transfer'), 'transfer'],
    ] as $key => [$url, $active, $icon])
        @if ($key === 'overview' || Gate::allows($key === 'transfer' ? 'export' : 'query', $database))
            <a href="{{ $url }}" @class(['btn', 'btn-primary' => $active, 'btn-outline-primary' => ! $active]) @if ($active) aria-current="page" @endif>
                <x-icon :name="$icon" class="me-1" />{{ __('databases.tool.nav.'.$key) }}
            </a>
        @endif
    @endforeach
</div>
