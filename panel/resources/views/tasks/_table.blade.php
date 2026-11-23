{{--
    Table of server tasks, used in the task list and on the server page.
    Expects $tasks and $showServer.
--}}
<div class="table-responsive">
    <table class="table table-vcenter card-table">
        <thead>
            <tr>
                <th scope="col">{{ __('tasks.columns.time') }}</th>
                @if ($showServer)
                    <th scope="col">{{ __('tasks.columns.server') }}</th>
                @endif
                <th scope="col">{{ __('tasks.columns.script') }}</th>
                <th scope="col">{{ __('tasks.columns.user') }}</th>
                <th scope="col">{{ __('tasks.columns.duration') }}</th>
                <th scope="col">{{ __('tasks.columns.status') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($tasks as $task)
                <tr>
                    <td class="text-nowrap"><x-time :value="$task->created_at" :stacked="true" /></td>
                    @if ($showServer)
                        <td>{{ $task->server->display_name }}</td>
                    @endif
                    <td>
                        <a href="{{ route('tasks.show', $task) }}" class="fw-medium">{{ __('tasks.scripts.'.$task->script->value) }}</a>
                        <div class="small text-secondary">
                            {{ $task->script->value }}@if ($task->site) · <a href="{{ route('sites.show', $task->site) }}" class="text-break">{{ $task->site->display_domain }}</a>@endif
                        </div>
                    </td>
                    <td>
                        @if ($task->user)
                            <div>{{ $task->user->name }}</div>
                            <div class="small text-secondary text-break">{{ $task->user->email }}</div>
                        @else
                            <span class="text-secondary">{{ __('tasks.console') }}</span>
                        @endif
                    </td>
                    <td class="text-nowrap">
                        @if (($seconds = $task->durationInSeconds()) !== null)
                            {{ __('tasks.seconds', ['count' => $seconds]) }}
                        @else
                            <span class="text-secondary">–</span>
                        @endif
                    </td>
                    <td>
                        {{-- Waiting and running tasks update themselves (E-049). --}}
                        @if ($task->isFinished())
                            <x-status-badge :status="$task->status->value" />
                        @else
                            <livewire:task-badge :task="$task" label="" :key="'task-'.$task->id" />
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
