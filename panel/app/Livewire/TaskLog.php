<?php

namespace App\Livewire;

use App\Enums\TaskStatus;
use App\Models\ServerTask;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Status and log of a server task. While the task is pending or running,
 * the browser asks for an update every second (wire:poll, E-049).
 *
 * Livewire is a Laravel package for interactive pages written in PHP and
 * Blade: the component renders on the server, and its JavaScript only
 * replaces the parts of the page that changed.
 */
class TaskLog extends Component
{
    /**
     * The ID travels through the browser; #[Locked] makes Livewire reject
     * any request that changes it.
     */
    #[Locked]
    public string $taskId;

    /**
     * Whether the task was finished at the last update. When it changes, the
     * component shows a toast with the outcome.
     */
    #[Locked]
    public bool $wasFinished = false;

    public function mount(ServerTask $task): void
    {
        $this->taskId = $task->getKey();
        $this->wasFinished = $task->isFinished();
    }

    public function render(): View
    {
        $task = ServerTask::with(['server', 'user', 'site', 'database'])->findOrFail($this->taskId);

        // Checked on every update, not only when the page is first shown.
        Gate::authorize('view', $task);

        if (! $this->wasFinished && $task->isFinished()) {
            $this->wasFinished = true;
            $this->dispatch('owpanel-toast',
                type: $task->status === TaskStatus::Succeeded ? 'success' : 'danger',
                message: $task->outcomeMessage(),
            );
        }

        return view('livewire.task-log', ['task' => $task]);
    }
}
