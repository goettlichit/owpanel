<?php

namespace App\Console\Commands;

use App\Notify\AlertChecker;
use App\Notify\Notifier;
use Illuminate\Console\Command;

/**
 * Checks for new and resolved problems and sends the messages held back
 * during quiet hours (E-101). Runs every minute.
 */
class SendNotifications extends Command
{
    protected $signature = 'owpanel:notifications';

    protected $description = 'Send alerts and all-clears by mail and push';

    public function handle(): int
    {
        (new AlertChecker)->run();
        Notifier::flushPending();

        return self::SUCCESS;
    }
}
