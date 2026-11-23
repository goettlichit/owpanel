<?php

namespace App\Jobs;

use App\Hetzner\HetznerCloud;
use App\Hetzner\HetznerException;
use App\Hetzner\SnapshotRotation;
use App\Models\ServerSnapshot;
use App\Notify\Events;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Checks whether Hetzner has finished a snapshot (E-095). While it runs,
 * the job comes back every 30 seconds; when it is done, old scheduled
 * snapshots of the server are removed according to its retention.
 * Snapshots by hand are never removed here.
 */
class FollowServerSnapshot implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $snapshotId) {}

    public function handle(): void
    {
        $run = ServerSnapshot::with('server')->find($this->snapshotId);
        if ($run === null || $run->status !== ServerSnapshot::CREATING || $run->hetzner_action_id === null) {
            return;
        }

        try {
            $hetzner = HetznerCloud::fromSettings();
            $action = $hetzner->action($run->hetzner_action_id);
        } catch (HetznerException $e) {
            // A short outage of the API is not the end of the snapshot.
            if (in_array($e->errorCode, ['connection_failed', 'rate_limited', 'api_error'], true) && ! $this->expired($run)) {
                self::dispatch($run->getKey())->delay(now()->addSeconds(60));

                return;
            }
            $run->fail($e->errorCode);

            return;
        }

        if ($action['status'] === 'running') {
            if ($this->expired($run)) {
                $run->fail('timeout');

                return;
            }
            self::dispatch($run->getKey())->delay(now()->addSeconds(30));

            return;
        }
        if ($action['status'] !== 'success') {
            $run->fail($action['error'] ?? 'snapshot_failed');

            return;
        }

        $run->forceFill(['status' => ServerSnapshot::DONE, 'finished_at' => now()])->save();
        Events::snapshotDone($run);
        $this->rotate($hetzner, $run);
    }

    /**
     * Deletes old panel snapshots of the server. A failure here leaves the
     * new snapshot in place and is noted on the run.
     */
    private function rotate(HetznerCloud $hetzner, ServerSnapshot $run): void
    {
        $server = $run->server;
        try {
            $snapshots = array_values(array_filter(
                $hetzner->snapshots(HetznerCloud::LABEL.'='.HetznerCloud::KIND_SCHEDULE.','.HetznerCloud::LABEL_SERVER.'='.strtolower((string) $server->getKey())),
                // Only scheduled snapshots; those by hand stay until deleted by a person.
                fn (array $image) => $image['status'] === 'available' && $image['panel'] && ! $image['manual']
                    && $image['panel_server'] === strtolower((string) $server->getKey()),
            ));
            $delete = SnapshotRotation::toDelete($snapshots, $server->snapshot_keep_daily, $server->snapshot_keep_weekly, (string) config('owpanel.timezone'));
            foreach ($delete as $imageId) {
                $hetzner->deleteImage($imageId);
            }
            $run->forceFill(['deleted_count' => count($delete)])->save();
        } catch (HetznerException $e) {
            $run->forceFill(['error' => 'rotation_'.$e->errorCode])->save();
        } catch (Throwable $e) {
            report($e);
            $run->forceFill(['error' => 'rotation_failed'])->save();
        }
    }

    private function expired(ServerSnapshot $run): bool
    {
        return $run->created_at->lt(now()->subMinutes(ServerSnapshot::MAX_MINUTES));
    }
}
