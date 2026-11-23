<?php

namespace App\Livewire;

use App\Jobs\ReadPanelBackupFiles;
use App\Models\Server;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Defer;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * What one backup of the panel holds (E-099), read from the Storage Box
 * after the page has loaded. Paths, sizes and times, never content.
 */
#[Defer]
class PanelBackupFiles extends Component
{
    /**
     * Known parts of a panel backup and how they are called.
     */
    private const KNOWN = [
        '/var/backups/owpanel/panel/owpanel.sql' => 'database',
        '/var/www/ow10000/shared/.env' => 'env',
        '/etc/owpanel/panel.conf' => 'state',
        '/var/lib/owpanel-worker/ssh/id_ed25519' => 'key',
        '/var/lib/owpanel-worker/ssh/id_ed25519.pub' => 'public_key',
        '/var/lib/owpanel-worker/ssh/known_hosts' => 'known_hosts',
    ];

    #[Locked]
    public string $serverId;

    #[Locked]
    public string $snapshot;

    public function mount(Server $server, string $snapshot): void
    {
        $this->serverId = (string) $server->getKey();
        $this->snapshot = $snapshot;
    }

    public function placeholder(): View
    {
        return view('livewire.backup-loading', ['text' => __('backups.show.loading'), 'card' => true]);
    }

    public function render(): View
    {
        $server = Server::findOrFail($this->serverId);
        Gate::authorize('manageBackups', $server);
        $answer = ReadPanelBackupFiles::await($this->serverId, $this->snapshot);
        if (isset($answer['files'])) {
            $answer['files'] = array_map(fn (array $file) => $file + ['kind' => self::KNOWN[$file['path']] ?? null], $answer['files']);
        }

        return view('livewire.panel-backup-files', ['answer' => $answer]);
    }
}
