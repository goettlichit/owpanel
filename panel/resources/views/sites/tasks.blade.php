<x-layouts.app :title="__('sites.menu.tasks').' · '.$site->display_domain">
    <x-slot:sidebar>
        @include('sites._site-menu')
    </x-slot:sidebar>

    <x-page-header :title="__('sites.menu.tasks')" :pretitle="$site->display_domain" />

    <x-card :flush="true">
        @if ($tasks->isEmpty())
            <div class="card-body text-secondary">{{ __('tasks.empty') }}</div>
        @else
            @include('tasks._table', ['tasks' => $tasks, 'showServer' => $showServer])
            @if ($tasks->hasPages())
                <div class="card-footer">{{ $tasks->links() }}</div>
            @endif
        @endif
    </x-card>
</x-layouts.app>
