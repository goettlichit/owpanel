<?php

namespace App\Servers;

use App\Models\ServerTask;

/**
 * Collects the output of a running task and writes it to the database at
 * most every half second, so the task view can follow it (E-049) without a
 * write for every few bytes. The stored log is limited in size.
 */
class TaskOutput
{
    public const MAX_BYTES = 1048576;

    private const FLUSH_INTERVAL = 0.5;

    private string $buffer = '';

    private bool $truncated = false;

    private float $lastFlush = 0.0;

    public function __construct(private readonly ServerTask $task) {}

    public function append(string $chunk): void
    {
        if (! $this->truncated) {
            $room = self::MAX_BYTES - strlen($this->buffer);
            if (strlen($chunk) > $room) {
                $chunk = substr($chunk, 0, max(0, $room))."\n[output truncated by the panel]\n";
                $this->truncated = true;
            }
            $this->buffer .= $chunk;
        }

        if (microtime(true) - $this->lastFlush >= self::FLUSH_INTERVAL) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        $this->lastFlush = microtime(true);
        // A chunk may end inside a multibyte character; the database only
        // accepts valid UTF-8. The raw buffer stays intact for the next flush.
        $this->task->forceFill(['output' => mb_scrub($this->buffer, 'UTF-8')])->save();
    }
}
