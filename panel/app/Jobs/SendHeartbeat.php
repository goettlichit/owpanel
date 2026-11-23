<?php

namespace App\Jobs;

use App\Support\Heartbeat;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Calls the heartbeat address (E-160). Runs in the query queue, which also
 * delivers the notifications, and only while every panel service answers.
 */
class SendHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onQueue(RunDatabaseQuery::QUEUE);
    }

    public function handle(): void
    {
        if (Heartbeat::stalled() === []) {
            Heartbeat::ping();
        }
    }
}
