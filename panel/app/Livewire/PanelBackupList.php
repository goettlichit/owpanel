<?php

namespace App\Livewire;

use App\Jobs\ReadPanelBackups;
use App\Models\Server;
use App\Models\Setting;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Defer;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Backups of the panel itself on Server -> Backups of its server (E-099), read from the
 * Storage Box after the page has loaded.
 */
#[Defer]
class PanelBackupList extends Component
{
    /** Backups shown per page. */
    public const PER_PAGE = 3;

    #[Locked]
    public string $serverId;

    /** Page of the list, 1 = the newest backups. */
    public int $current = 1;

    /**
     * The answer of the Storage Box, read once when the list loads, so
     * paging does not ask the server again.
     *
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $answer = null;

    public function mount(Server $server): void
    {
        $this->serverId = (string) $server->getKey();
    }

    public function placeholder(): View
    {
        return view('livewire.backup-loading', ['text' => __('backups.list.loading')]);
    }

    public function older(): void
    {
        $this->current++;
    }

    public function newer(): void
    {
        $this->current = max(1, $this->current - 1);
    }

    public function render(): View
    {
        $server = Server::findOrFail($this->serverId);
        Gate::authorize('manageBackups', $server);

        $answer = null;
        if ($server->hostsPanel() && $server->backupActive()) {
            $this->answer ??= ReadPanelBackups::await((string) $server->getKey());
            $answer = $this->answer;
        }
        $snapshots = is_array($answer['snapshots'] ?? null) ? array_values($answer['snapshots']) : [];
        $pages = max(1, (int) ceil(count($snapshots) / self::PER_PAGE));
        $this->current = min(max(1, $this->current), $pages);

        return view('livewire.panel-backup-list', [
            'server' => $server,
            'restoredId' => Setting::panelRestored()['snapshot'] ?? null,
            'answer' => $answer,
            'page' => array_slice($snapshots, ($this->current - 1) * self::PER_PAGE, self::PER_PAGE),
            'pages' => $pages,
        ]);
    }
}
