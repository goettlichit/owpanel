<?php

namespace App\Jobs;

use App\Workers\PanelWorkers;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A tiny job the scheduler puts into each queue once a minute (E-133).
 * When a worker runs it, the worker is not only up but takes jobs. Unique
 * per queue, so a paused or stuck worker collects at most one of them.
 */
class QueueHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public string $queueName)
    {
        $this->onQueue($queueName);
    }

    public function uniqueId(): string
    {
        return $this->queueName;
    }

    public function handle(): void
    {
        PanelWorkers::beat($this->queueName);
    }
}
