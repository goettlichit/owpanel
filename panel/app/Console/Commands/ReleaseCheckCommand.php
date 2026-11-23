<?php

namespace App\Console\Commands;

use App\Support\PanelRelease;
use Illuminate\Console\Command;

/**
 * Looks for a newer release of the panel (E-154). Runs once a day.
 */
class ReleaseCheckCommand extends Command
{
    protected $signature = 'owpanel:release-check';

    protected $description = 'Look for a newer release of the panel';

    public function handle(): int
    {
        PanelRelease::check();

        return self::SUCCESS;
    }
}
