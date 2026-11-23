<?php

namespace App\Livewire;

use App\Enums\SiteStatus;
use App\Jobs\ReadLog;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Defer;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The end of a log of a server or a website (E-086, E-087), read from the
 * server after the page has loaded.
 */
#[Defer]
class LogViewer extends Component
{
    /** ReadLog::SERVER or ReadLog::SITE. */
    #[Locked]
    public string $target;

    #[Locked]
    public string $targetId;

    #[Locked]
    public string $log;

    #[Locked]
    public int $lines;

    /** Route of the page, for the buttons that change the lines. */
    #[Locked]
    public string $route;

    public function mount(string $target, Server|Site $subject, string $log, int $lines, string $route): void
    {
        $this->target = $target === ReadLog::SITE ? ReadLog::SITE : ReadLog::SERVER;
        $this->targetId = (string) $subject->getKey();
        $this->log = $log;
        $this->lines = $lines;
        $this->route = $route;
    }

    public function placeholder(): View
    {
        return view('livewire.card-loading', ['title' => __('logs.kinds.'.$this->log)]);
    }

    public function render(): View
    {
        if ($this->target === ReadLog::SITE) {
            $subject = Site::findOrFail($this->targetId);
            Gate::authorize('viewLogs', $subject);
            $answer = $subject->status === SiteStatus::Active
                ? ReadLog::await(ReadLog::SITE, $this->targetId, $this->log, $this->lines)
                : null;
        } else {
            $subject = Server::findOrFail($this->targetId);
            Gate::authorize('view', $subject);
            $answer = ReadLog::await(ReadLog::SERVER, $this->targetId, $this->log, $this->lines);
        }
        $route = $this->route;

        return view('livewire.log-viewer', [
            'log' => $this->log,
            'lines' => $this->lines,
            'answer' => $answer,
            'url' => fn (string $key, int $count) => route($route, [$subject, 'log' => $key, 'lines' => $count]),
        ]);
    }
}
