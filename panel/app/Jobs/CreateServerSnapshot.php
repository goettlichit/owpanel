<?php

namespace App\Jobs;

use App\Hetzner\HetznerCloud;
use App\Hetzner\HetznerException;
use App\Models\ServerSnapshot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Starts a Hetzner snapshot of a server (E-095). Finds the Hetzner server
 * by its address the first time, then asks Hetzner for the image and hands
 * over to FollowServerSnapshot, which waits for it without blocking the
 * worker.
 */
class CreateServerSnapshot implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $snapshotId) {}

    public function handle(): void
    {
        $run = ServerSnapshot::with('server')->find($this->snapshotId);
        if ($run === null || $run->status !== ServerSnapshot::PENDING) {
            return;
        }
        $server = $run->server;

        try {
            $hetzner = HetznerCloud::fromSettings();
            if ($server->hetzner_server_id === null) {
                $found = $hetzner->findServer($server->public_ipv4, $server->public_ipv6);
                if ($found === null) {
                    $run->fail('server_not_found');

                    return;
                }
                $server->forceFill(['hetzner_server_id' => $found['id']])->save();
            }

            $started = $hetzner->createSnapshot($server->hetzner_server_id, sprintf(
                'OWPanel %s %s', $server->name, now()->setTimezone((string) config('owpanel.timezone'))->format('Y-m-d H:i'),
            ), [
                // Snapshots by hand are never rotated away.
                HetznerCloud::LABEL => $run->trigger === 'manual' ? HetznerCloud::KIND_MANUAL : HetznerCloud::KIND_SCHEDULE,
                HetznerCloud::LABEL_SERVER => strtolower((string) $server->getKey()),
            ]);
        } catch (HetznerException $e) {
            $run->fail($e->errorCode);

            return;
        } catch (Throwable $e) {
            report($e);
            $run->fail('api_error');

            return;
        }

        $run->forceFill([
            'status' => ServerSnapshot::CREATING,
            'hetzner_image_id' => $started['image_id'],
            'hetzner_action_id' => $started['action_id'],
        ])->save();

        FollowServerSnapshot::dispatch($run->getKey())->delay(now()->addSeconds(30));
    }
}
