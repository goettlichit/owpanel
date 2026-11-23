<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\Audit;
use App\Workers\PanelWorkers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * System -> Services (E-133): state of the panel's workers and scheduler,
 * restart of the workers, pause and resume of a queue. Administrators only;
 * every action is in the audit log.
 */
class PanelWorkerController extends Controller
{
    public function index(): View
    {
        Gate::authorize('manage', Setting::class);

        return view('system.workers', [
            'workers' => array_map(fn (string $queue) => PanelWorkers::worker($queue), array_keys(PanelWorkers::QUEUES)),
            'scheduler' => PanelWorkers::scheduler(),
            'lastRestart' => PanelWorkers::lastRestart(),
        ]);
    }

    public function restart(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Setting::class);

        PanelWorkers::restart();
        Audit::record('system.workers_restarted', null, [], $request->user());

        return redirect()->route('workers.index')->with('status', __('workers.toast.restarted'));
    }

    public function pause(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Setting::class);
        $queue = $this->queue($request);

        PanelWorkers::pause($queue);
        Audit::record('system.queue_paused', null, ['queue' => $queue], $request->user());

        return back()->with('status', __('workers.toast.paused', ['name' => __('workers.names.'.$queue)]));
    }

    public function resume(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Setting::class);
        $queue = $this->queue($request);

        PanelWorkers::resume($queue);
        Audit::record('system.queue_resumed', null, ['queue' => $queue], $request->user());

        return back()->with('status', __('workers.toast.resumed', ['name' => __('workers.names.'.$queue)]));
    }

    private function queue(Request $request): string
    {
        return $request->validate(['queue' => ['required', 'string', Rule::in(array_keys(PanelWorkers::QUEUES))]])['queue'];
    }
}
