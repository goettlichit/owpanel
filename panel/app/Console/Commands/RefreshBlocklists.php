<?php

namespace App\Console\Commands;

use App\Firewall\Blocklists;
use App\Models\Blocklist;
use App\Models\Server;
use Illuminate\Console\Command;

/**
 * Fetches the blocklists that are due (E-170) and loads the changed ones
 * onto their servers. Runs once an hour.
 */
class RefreshBlocklists extends Command
{
    protected $signature = 'owpanel:blocklists';

    protected $description = 'Fetch due blocklists and load changed ones onto their servers';

    public function handle(): int
    {
        $changed = [];
        foreach (Blocklist::query()->orderBy('checked_at')->get() as $list) {
            if (! $list->due()) {
                continue;
            }
            $result = Blocklists::refresh($list);
            if ($result['changed']) {
                $changed[$list->server_id] = true;
            }
            $this->line($list->label().': '.($result['ok'] ? ($result['changed'] ? 'changed' : 'unchanged') : 'failed ('.$result['error'].')'));
        }

        foreach (Server::query()->whereKey(array_keys($changed))->get() as $server) {
            Blocklists::apply($server, null);
        }

        return self::SUCCESS;
    }
}
