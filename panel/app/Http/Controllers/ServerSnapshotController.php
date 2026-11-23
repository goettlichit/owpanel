<?php

namespace App\Http\Controllers;

use App\Hetzner\HetznerCloud;
use App\Hetzner\HetznerException;
use App\Jobs\CreateServerSnapshot;
use App\Models\Alert;
use App\Models\Server;
use App\Models\ServerSnapshot;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Snapshots of a server at Hetzner (E-095): schedule and retention, a
 * snapshot on demand, the list of images and the runs of the panel.
 */
class ServerSnapshotController extends Controller
{
    /** Snapshots and runs shown per page. */
    private const PER_PAGE = 5;

    public function index(Request $request, Server $server): View
    {
        Gate::authorize('manageSnapshots', $server);

        $images = [];
        $error = null;
        if (HetznerCloud::configured() && $server->hetzner_server_id !== null) {
            try {
                // All snapshots of this Hetzner server, also those created by hand.
                $images = array_values(array_filter(
                    HetznerCloud::fromSettings()->snapshots(),
                    fn (array $image) => $image['server_id'] === $server->hetzner_server_id
                        || $image['panel_server'] === strtolower((string) $server->getKey()),
                ));
            } catch (HetznerException $e) {
                $error = $e->errorCode;
            }
        }

        // Newest first, five per page (?images=2 …).
        usort($images, fn (array $a, array $b) => strcmp((string) $b['created'], (string) $a['created']));
        $page = max(1, (int) $request->query('images', '1'));
        $images = new LengthAwarePaginator(
            array_slice($images, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            count($images), self::PER_PAGE, $page,
            ['path' => route('servers.snapshots', $server), 'pageName' => 'images'],
        );

        return view('servers.snapshots', [
            'server' => $server,
            'configured' => HetznerCloud::configured(),
            'images' => $images,
            'error' => $error,
            'busy' => $this->busy($server),
            // Line of the schedule card (docs/DESIGN.md): blue off, green on, red when the latest snapshot failed.
            'accent' => match (true) {
                ! HetznerCloud::configured() || ! $server->snapshot_enabled => 'blue',
                Alert::whereNull('resolved_at')->where('key', 'snapshot_failed:'.$server->getKey())->exists() => 'red',
                default => 'green',
            },
        ]);
    }

    public function updateSchedule(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('manageSnapshots', $server);

        $validated = $request->validate([
            'snapshot_enabled' => ['nullable', 'boolean'],
            'snapshot_time' => ['required', 'date_format:H:i'],
            'snapshot_keep_daily' => ['required', 'integer', 'between:1,31'],
            'snapshot_keep_weekly' => ['required', 'integer', 'between:0,12'],
        ]);
        $server->forceFill([
            'snapshot_enabled' => $request->boolean('snapshot_enabled'),
            'snapshot_time' => $validated['snapshot_time'],
            'snapshot_keep_daily' => (int) $validated['snapshot_keep_daily'],
            'snapshot_keep_weekly' => (int) $validated['snapshot_keep_weekly'],
        ])->save();
        Audit::record('server.snapshot_schedule_changed', $server, [
            'enabled' => $server->snapshot_enabled, 'time' => $server->snapshot_time,
            'daily' => $server->snapshot_keep_daily, 'weekly' => $server->snapshot_keep_weekly,
        ], $request->user());

        return redirect()->route('servers.snapshots', $server)->with('status', __('snapshots.toast.schedule_saved'));
    }

    public function store(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('manageSnapshots', $server);

        if (! HetznerCloud::configured()) {
            return back()->with('error', __('snapshots.errors.not_configured'));
        }
        if (! $server->snapshot_enabled) {
            return back()->with('error', __('snapshots.toast.disabled'));
        }
        if ($this->busy($server)) {
            return back()->with('error', __('snapshots.toast.busy'));
        }

        $run = $server->snapshots()->create(['trigger' => 'manual', 'status' => ServerSnapshot::PENDING, 'user_id' => $request->user()->getKey()]);
        CreateServerSnapshot::dispatch($run->getKey());
        Audit::record('server.snapshot_started', $server, [], $request->user());

        return redirect()->route('servers.snapshots', $server)->with('status', __('snapshots.toast.started'));
    }

    /**
     * Deletes a snapshot the panel created for this server; snapshots made
     * by hand in the Hetzner console stay untouched.
     */
    public function destroy(Request $request, Server $server, int $image): RedirectResponse
    {
        Gate::authorize('manageSnapshots', $server);

        try {
            $hetzner = HetznerCloud::fromSettings();
            $label = strtolower((string) $server->getKey());
            $own = collect($hetzner->snapshots(HetznerCloud::LABEL_SERVER.'='.$label))
                ->first(fn (array $snapshot) => $snapshot['id'] === $image && $snapshot['panel'] && $snapshot['panel_server'] === $label);
            abort_if($own === null, 404);
            $hetzner->deleteImage($image);
        } catch (HetznerException $e) {
            return back()->with('error', __('snapshots.toast.delete_failed', ['error' => __('snapshots.errors.'.$e->errorCode)]));
        }
        Audit::record('server.snapshot_deleted', $server, ['image' => $image], $request->user());

        return redirect()->route('servers.snapshots', $server)->with('status', __('snapshots.toast.deleted'));
    }

    private function busy(Server $server): bool
    {
        return $server->snapshots()->whereIn('status', [ServerSnapshot::PENDING, ServerSnapshot::CREATING])->exists();
    }
}
