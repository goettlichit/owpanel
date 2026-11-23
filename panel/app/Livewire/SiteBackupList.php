<?php

namespace App\Livewire;

use App\Jobs\ReadSiteBackups;
use App\Models\Site;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Defer;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The restic snapshots of a website (E-096, E-098). Reading them means a
 * connection from the server to the Storage Box, which takes a few
 * seconds; the page shows at once and the list follows.
 */
#[Defer]
class SiteBackupList extends Component
{
    #[Locked]
    public string $siteId;

    /**
     * Route prefix of the page: "" in the administration, "my." in the
     * customer area (E-145). Passed in, because a deferred component mounts
     * in its own request.
     */
    #[Locked]
    public string $area = '';

    public function mount(Site $site, string $area = ''): void
    {
        $this->siteId = (string) $site->getKey();
        $this->area = $area === 'my.' ? 'my.' : '';
    }

    public function placeholder(): View
    {
        return view('livewire.backup-loading', ['text' => __('backups.list.loading')]);
    }

    public function render(): View
    {
        $site = Site::findOrFail($this->siteId);
        Gate::authorize('manageBackups', $site);

        return view('livewire.site-backup-list', [
            'area' => $this->area,
            'site' => $site,
            'answer' => ReadSiteBackups::await($this->siteId),
        ]);
    }
}
