<?php

namespace App\Livewire;

use App\Models\ServerTask;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Small status badge of a server task with a link to it: in the task list,
 * and on pages whose last change to the server failed (cron jobs, SFTP,
 * firewall, backups). Running tasks show at their button instead (E-152). While the task is
 * pending or running, the browser asks for an update every two seconds and
 * stops once it is finished (wire:poll, E-049). Customers see the state
 * of tasks of their own websites, but no link to the task log (E-143).
 */
class TaskBadge extends Component
{
    #[Locked]
    public string $taskId;

    #[Locked]
    public string $label;

    public function mount(ServerTask $task, string $label): void
    {
        $this->taskId = $task->getKey();
        $this->label = $label;
    }

    public function render(): View
    {
        $task = ServerTask::findOrFail($this->taskId);
        $full = Gate::allows('view', $task);
        abort_unless($full || ($task->site !== null && Gate::allows('view', $task->site)), 403);

        return view('livewire.task-badge', ['task' => $task, 'link' => $full]);
    }
}
