<?php

namespace App\Livewire;

use App\Jobs\ReadSiteBackupFiles;
use App\Models\Site;
use App\Servers\ScriptCall;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Defer;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * What a snapshot of a website holds (E-098): one directory below htdocs
 * at a time, and the database dumps. Each step reads from the Storage Box
 * through the server, so the first view follows the page.
 */
#[Defer]
class SiteBackupBrowser extends Component
{
    #[Locked]
    public string $siteId;

    #[Locked]
    public string $snapshot;

    /**
     * Directory below htdocs, "" for htdocs itself.
     */
    public string $path = '';

    /**
     * Route prefix of the page: "" in the administration, "my." in the
     * customer area (E-145).
     */
    #[Locked]
    public string $area = '';

    public function mount(Site $site, string $snapshot, string $area = ''): void
    {
        $this->siteId = (string) $site->getKey();
        $this->snapshot = $snapshot;
        $this->area = $area === 'my.' ? 'my.' : '';
    }

    public function open(string $name): void
    {
        if ($name === '' || str_contains($name, '/')) {
            return;
        }
        $this->path = $this->path === '' ? $name : $this->path.'/'.$name;
    }

    /**
     * Goes to a level of the breadcrumb: 0 is htdocs.
     */
    public function go(int $depth): void
    {
        $parts = $this->path === '' ? [] : explode('/', $this->path);
        $this->path = implode('/', array_slice($parts, 0, max(0, $depth)));
    }

    public function placeholder(): View
    {
        return view('livewire.backup-loading', ['text' => __('backups.show.loading'), 'card' => true]);
    }

    public function render(): View
    {
        $site = Site::findOrFail($this->siteId);
        Gate::authorize('manageBackups', $site);
        if (! ScriptCall::validSitePath($this->path)) {
            $this->path = '';
        }

        return view('livewire.site-backup-browser', [
            'area' => $this->area,
            'site' => $site,
            'answer' => ReadSiteBackupFiles::await($this->siteId, $this->snapshot, $this->path),
            'parts' => $this->path === '' ? [] : explode('/', $this->path),
        ]);
    }
}
