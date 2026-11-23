<x-layouts.app :title="__('tasks.details.title').' · '.__('tasks.scripts.'.$task->script->value)">
    {{-- The menu of the website or server the task belongs to. --}}
    <x-slot:sidebar>
        @if ($task->site)
            @include('sites._site-menu', ['site' => $task->site])
        @else
            @include('servers._menu', ['server' => $task->server])
        @endif
    </x-slot:sidebar>

    @php($back = $task->backTarget())
    <x-page-header :title="__('tasks.scripts.'.$task->script->value)" :pretitle="__('tasks.details.title')">
        <x-slot:actions>
            <a href="{{ $back['url'] }}" class="btn btn-outline-primary">
                <x-icon name="arrow-left" class="me-1" />{{ $back['label'] }}
            </a>
        </x-slot:actions>
    </x-page-header>

    <livewire:task-log :task="$task" />
</x-layouts.app>
