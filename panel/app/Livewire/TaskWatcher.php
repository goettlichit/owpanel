<?php

namespace App\Livewire;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One way to follow server tasks on every page (E-152): the page stays where
 * it is, the button that started a task shows a spinner with its text, and
 * this component asks every two seconds whether the tasks of the page are
 * done. When one is, it reloads the page, which then shows the new state,
 * with a short message for tasks the user started himself: green when done,
 * red when failed, for administrators with a link to the log.
 */
class TaskWatcher extends Component
{
    #[Locked]
    public string $type;

    #[Locked]
    public ?string $subject;

    #[Locked]
    public string $url;

    /** @var list<string> Open tasks this page waits for. */
    #[Locked]
    public array $watching = [];

    public function mount(string $type, ?string $subject = null): void
    {
        $this->type = $type;
        $this->subject = $subject;
        $this->url = request()->fullUrl();
        $this->authorizeScope();
        $this->watching = $this->open()->pluck('id')->all();
    }

    public function check(): void
    {
        $this->authorizeScope();
        $open = $this->open()->pluck('id')->all();
        $finished = array_values(array_diff($this->watching, $open));
        if ($finished === []) {
            $this->watching = array_values(array_unique([...$this->watching, ...$open]));

            return;
        }

        $user = auth()->user();
        ServerTask::whereIn('id', $finished)->where('user_id', $user->getKey())->get()
            ->each(function (ServerTask $task) use ($user): void {
                $name = __('tasks.scripts.'.$task->script->value);
                if ($task->status === TaskStatus::Succeeded) {
                    // panel-update --version only starts the installer; the
                    // card of the update reports the end (E-185).
                    if ($task->script === ServerScript::PanelUpdate && in_array('--version', (array) $task->arguments, true)) {
                        return;
                    }
                    session()->flash('status', __('tasks.feedback.done', ['task' => $name]));

                    return;
                }
                session()->flash('error', __($user->isAdmin() ? 'tasks.feedback.failed' : 'tasks.feedback.failed_customer', ['task' => $name]));
                if (Gate::forUser($user)->allows('view', $task)) {
                    session()->flash('error_link', route('tasks.show', $task));
                }
            });

        $this->redirect($this->url);
    }

    public function render(): View
    {
        $open = $this->open()->get(['id', 'script', 'user_id']);

        return view('livewire.task-watcher', [
            'open' => $open,
            'scripts' => $open->map(fn (ServerTask $task) => $task->script->value)->unique()->values()->all(),
            'own' => $open->firstWhere('user_id', auth()->id()),
        ]);
    }

    /**
     * @return Builder<ServerTask>
     */
    private function open(): Builder
    {
        $query = ServerTask::query()->whereIn('status', [TaskStatus::Pending->value, TaskStatus::Running->value]);

        return match ($this->type) {
            'site' => $query->where('site_id', $this->subject),
            'server' => $query->where('server_id', $this->subject),
            default => $query->where('user_id', auth()->id()),
        };
    }

    private function authorizeScope(): void
    {
        match ($this->type) {
            'site' => Gate::authorize('view', Site::findOrFail($this->subject)),
            'server' => abort_unless(auth()->user()?->isAdmin() && Server::whereKey($this->subject)->exists(), 403),
            'user' => abort_unless(auth()->check(), 403),
            default => abort(403),
        };
    }
}
