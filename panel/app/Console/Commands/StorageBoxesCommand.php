<?php

namespace App\Console\Commands;

use App\Backups\StorageBoxUsage;
use Illuminate\Console\Command;

/**
 * Reads the fill level of the Storage Boxes (E-160). Runs once an hour.
 */
class StorageBoxesCommand extends Command
{
    protected $signature = 'owpanel:storage-boxes';

    protected $description = 'Read the fill level of the Storage Boxes used for backups';

    public function handle(): int
    {
        StorageBoxUsage::refresh();

        return self::SUCCESS;
    }
}
