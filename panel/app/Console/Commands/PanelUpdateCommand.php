<?php

namespace App\Console\Commands;

use App\Servers\ScriptRunner;
use App\Support\PanelUpdate;
use Illuminate\Console\Command;

/**
 * Follows a running update of the panel once a minute, and with --auto
 * starts the nightly automatic update when it is switched on (E-185).
 */
class PanelUpdateCommand extends Command
{
    protected $signature = 'owpanel:panel-update {--auto : Start the automatic update if one is due}';

    protected $description = 'Follow a running update of the panel, or start the automatic one';

    public function handle(ScriptRunner $runner): int
    {
        if ($this->option('auto')) {
            PanelUpdate::runAutomatic();
        } else {
            PanelUpdate::follow($runner);
        }

        return self::SUCCESS;
    }
}
