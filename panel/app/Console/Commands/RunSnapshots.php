<?php

namespace App\Console\Commands;

use App\Hetzner\HetznerCloud;
use App\Jobs\CreateServerSnapshot;
use App\Models\Server;
use App\Models\ServerSnapshot;
use Illuminate\Console\Command;

/**
 * Starts the scheduled Hetzner snapshots (E-095). Runs every minute; a
 * server is due at its time in the panel's time zone, once a day. A run
 * missed because the panel was down is made up later the same day.
 */
class RunSnapshots extends Command
{
    protected $signature = 'owpanel:snapshots';

    protected $description = 'Start scheduled Hetzner snapshots';

    public function handle(): int
    {
        if (! HetznerCloud::configured()) {
            return self::SUCCESS;
        }

        $timezone = (string) config('owpanel.timezone');
        $now = now()->setTimezone($timezone);

        Server::where('snapshot_enabled', true)->each(function (Server $server) use ($now, $timezone) {
            if ($now->format('H:i') < $server->snapshot_time) {
                return;
            }
            // Counted from today's snapshot time, so a time moved later in
            // the day takes effect the same day.
            $startOfDay = $now->copy()->setTimeFromTimeString($server->snapshot_time)->utc();
            $ranToday = $server->snapshots()->where('trigger', 'schedule')->where('created_at', '>=', $startOfDay)->exists();
            $busy = $server->snapshots()->whereIn('status', [ServerSnapshot::PENDING, ServerSnapshot::CREATING])->exists();
            if ($ranToday || $busy) {
                return;
            }

            $run = $server->snapshots()->create(['trigger' => 'schedule', 'status' => ServerSnapshot::PENDING]);
            CreateServerSnapshot::dispatch($run->getKey());
            $this->line("{$server->name}: snapshot started ({$timezone} {$now->format('H:i')})");
        });

        return self::SUCCESS;
    }
}
