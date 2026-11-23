<?php

namespace App\Enums;

/**
 * State of a server task. The values match the keys in lang/{de,en}/status.php
 * and <x-status-badge>.
 */
enum TaskStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }
}
