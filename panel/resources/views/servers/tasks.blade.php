<x-layouts.app :title="__('servers.menu.tasks').' · '.$server->display_name">
    <x-slot:sidebar>
        @include('servers._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('servers.menu.tasks')" :pretitle="$server->display_name" />

    <x-card :flush="true">
        @if ($tasks->isEmpty())
            <div class="card-body text-secondary">{{ __('tasks.empty') }}</div>
        @else
            @include('tasks._table', ['tasks' => $tasks, 'showServer' => false])
            @if ($tasks->hasPages())
                <div class="card-footer">{{ $tasks->links() }}</div>
            @endif
        @endif
    </x-card>
</x-layouts.app>
